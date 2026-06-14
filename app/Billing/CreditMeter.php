<?php

namespace App\Billing;

use App\Billing\Exceptions\OutOfCredits;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Notifications\CreditBurnAlertNotification;
use App\Notifications\OutOfCreditsNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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

        try {
            DB::transaction(function () use ($team, $amount, $agentId, $meta) {
                // Re-read with lock so the check + decrement is atomic.
                $fresh = Team::lockForUpdate()->find($team->id);

                if (! $fresh->hasCredits($amount)) {
                    throw new OutOfCredits($fresh->planObject());
                }

                // Two-bucket drain: the monthly allowance first, then
                // rolled-over purchased top-ups — paid credits are the
                // last to go (they survive renewals; monthly does not).
                $fromMonthly = min($amount, (int) $fresh->credit_balance);
                $fromTopup = $amount - $fromMonthly;

                $fresh->forceFill([
                    'credit_balance' => $fresh->credit_balance - $fromMonthly,
                    'topup_balance' => $fresh->topup_balance - $fromTopup,
                ])->save();

                CreditTransaction::create([
                    'team_id' => $fresh->id,
                    'agent_id' => $agentId,
                    'amount' => -$amount,
                    'reason' => CreditTransaction::REASON_CONSUME_MESSAGE,
                    'meta' => $fromTopup > 0 ? array_merge($meta, ['from_topup' => $fromTopup]) : $meta,
                ]);

                $this->evaluateAndDispatchAlerts($fresh);

                // Refresh the in-memory model passed in by the caller so they
                // see the new balance without re-fetching.
                $team->refresh();
            });
        } catch (OutOfCredits $e) {
            // Notification AFTER the transaction commits its rollback so the
            // alert_thresholds_fired update actually persists. Without this
            // ordering the save would be wiped along with the failed debit.
            $this->notifyOutOfCreditsOnce($team->fresh());
            throw $e;
        }
    }

    /**
     * Evaluate which credit-burn thresholds (50/80/95% used) the team has
     * just crossed, persist the new fired-state, and dispatch one
     * CreditBurnAlertNotification per newly-crossed threshold to the team owner.
     *
     * Idempotent — `alert_thresholds_fired` ensures we never warn twice for
     * the same crossing in a billing period. Top-ups remove thresholds that
     * the new balance has climbed back above, so a drop-then-top-then-drop
     * cycle correctly re-fires.
     */
    protected function evaluateAndDispatchAlerts(Team $team): void
    {
        $result = (new EvaluateCreditAlerts)->evaluate($team);

        $needsPersist = $result['fired'] !== ($team->alert_thresholds_fired ?? []);
        if ($needsPersist) {
            $team->forceFill(['alert_thresholds_fired' => $result['fired']])->save();
        }

        if ($result['newlyCrossed'] === []) {
            return;
        }

        $owner = $team->owner;
        if ($owner === null) {
            return;
        }

        $grant = $team->planObject()->monthlyCredits();
        foreach ($result['newlyCrossed'] as $threshold) {
            Notification::send($owner, new CreditBurnAlertNotification(
                team: $team,
                thresholdPercent: $threshold,
                creditsRemaining: (int) $team->credit_balance,
                monthlyGrant: $grant,
            ));
        }
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
            // Ledger integrity: the reset WIPES leftover monthly credits —
            // record that as a negative adjustment so SUM(credit_transactions)
            // always equals credit_balance + topup_balance (credits:reconcile
            // asserts this). Without this row the audit trail could never
            // balance and drift was undetectable.
            $expired = max(0, (int) $team->credit_balance);
            if ($expired > 0) {
                CreditTransaction::create([
                    'team_id' => $team->id,
                    'amount' => -$expired,
                    'reason' => CreditTransaction::REASON_EXPIRE_MONTHLY,
                    'meta' => ['at_renewal' => true],
                ]);
            }

            $team->forceFill([
                // Hard reset of the MONTHLY bucket only — no rollover for
                // the allowance. topup_balance is untouched: purchased
                // credits persist until spent.
                'credit_balance' => $amount,
                'credits_renewed_at' => now(),
                // Wipe fired thresholds: new billing period means even if
                // they immediately burn down again, they should see the
                // alerts as fresh events.
                'alert_thresholds_fired' => (new EvaluateCreditAlerts)->reset(),
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
     * Add credits from a one-off top-up purchase. Lands in the rollover
     * bucket — survives monthly renewals until spent.
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
            // Purchased credits live in their own bucket: they roll over
            // across renewals until spent (policy 2026-06-12).
            $team->forceFill([
                'topup_balance' => $team->topup_balance + $amount,
            ])->save();

            CreditTransaction::create([
                'team_id' => $team->id,
                'amount' => $amount,
                'reason' => CreditTransaction::REASON_GRANT_TOPUP,
                'meta' => $meta,
            ]);

            // Top-up may have lifted balance back above one or more thresholds —
            // recompute and persist (the array shrinks). No notifications
            // fire on top-up; that direction is good news, not an alert.
            // Also clear any '100' (out-of-credits) flag — they're back in business.
            $result = (new EvaluateCreditAlerts)->evaluate($team);
            $fired = $result['fired'];
            if ($fired !== ($team->alert_thresholds_fired ?? [])) {
                $team->forceFill(['alert_thresholds_fired' => $fired])->save();
            }
        });
    }

    /**
     * Notify the team owner the first time a turn is refused for lack of
     * credits this period. Idempotent via Team.alert_thresholds_fired —
     * we tack on '100' once dispatched; renewal/top-up clears it through
     * EvaluateCreditAlerts.
     */
    protected function notifyOutOfCreditsOnce(Team $team): void
    {
        $fired = $team->alert_thresholds_fired ?? [];
        if (in_array('100', $fired, true)) {
            return;
        }

        $owner = $team->owner;
        if ($owner === null) {
            return;
        }

        Notification::send($owner, new OutOfCreditsNotification(team: $team));

        $fired[] = '100';
        $team->forceFill(['alert_thresholds_fired' => $fired])->save();
    }
}
