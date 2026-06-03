<?php

namespace App\Billing;

use App\Billing\Exceptions\OutOfCredits;
use App\Models\CreditTransaction;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for credit grants and consumption. Every mutation
 * goes through a DB transaction that updates the team's cached balance
 * AND writes an audit row, so the two never drift.
 *
 * The audit table (credit_transactions) is the ground truth — if the
 * cached balance is ever suspect, you can reconcile via:
 *   SELECT team_id, SUM(amount) FROM credit_transactions GROUP BY team_id.
 */
class CreditMeter
{
    /**
     * Consume credits or throw if insufficient. The check + decrement
     * happens inside a transaction with row-level lock so concurrent
     * requests can't over-spend.
     *
     * @param  array<string, mixed>  $meta  Free-form audit context (conversation_id, message_id, ...).
     */
    public function consume(Team $team, int $amount, ?int $agentId = null, array $meta = []): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($team, $amount, $agentId, $meta) {
            // Re-read with lock so the check + decrement is atomic.
            $fresh = Team::lockForUpdate()->find($team->id);

            if (! $fresh->hasCredits($amount)) {
                throw new OutOfCredits($fresh->planObject());
            }

            $fresh->forceFill([
                'credit_balance' => $fresh->credit_balance - $amount,
            ])->save();

            CreditTransaction::create([
                'team_id' => $fresh->id,
                'agent_id' => $agentId,
                'amount' => -$amount,
                'reason' => CreditTransaction::REASON_CONSUME_MESSAGE,
                'meta' => $meta,
            ]);

            // Refresh the in-memory model passed in by the caller so they
            // see the new balance without re-fetching.
            $team->refresh();
        });
    }

    /**
     * Reset the team's monthly allotment (used by the renewal job + the
     * Stripe invoice_paid webhook). Idempotent — won't grant twice in the
     * same billing period.
     */
    public function grantMonthlyRenewal(Team $team, ?array $meta = null): void
    {
        $plan = $team->planObject();
        $amount = $plan->monthlyCredits();

        DB::transaction(function () use ($team, $plan, $amount, $meta) {
            $team->forceFill([
                // Hard reset, not additive — "no rollover" semantics.
                'credit_balance' => $amount,
                'credits_renewed_at' => now(),
            ])->save();

            CreditTransaction::create([
                'team_id' => $team->id,
                'amount' => $amount,
                'reason' => CreditTransaction::REASON_GRANT_RENEWAL,
                'meta' => array_merge(['plan' => $plan->value], $meta ?? []),
            ]);
        });
    }

    /**
     * Add credits from a one-off top-up purchase (Pro+ only). Additive —
     * stacks on top of whatever the user has left this month.
     */
    public function grantTopUp(Team $team, int $amount, array $meta = []): void
    {
        if ($amount <= 0) {
            return;
        }

        if (! $team->planObject()->allowsTopUps()) {
            throw new \RuntimeException("Plan {$team->planObject()->label()} does not allow top-ups.");
        }

        DB::transaction(function () use ($team, $amount, $meta) {
            $team->forceFill([
                'credit_balance' => $team->credit_balance + $amount,
            ])->save();

            CreditTransaction::create([
                'team_id' => $team->id,
                'amount' => $amount,
                'reason' => CreditTransaction::REASON_GRANT_TOPUP,
                'meta' => $meta,
            ]);
        });
    }
}
