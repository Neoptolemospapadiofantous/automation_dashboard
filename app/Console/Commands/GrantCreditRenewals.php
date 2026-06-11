<?php

namespace App\Console\Commands;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Monthly-credit renewal safety net, scheduled daily.
 *
 * Monthly subscriptions renew via the invoice.paid webhook. That leaves
 * two gaps the 2026-06-11 pricing audit found: ANNUAL subscriptions only
 * invoice once a year (so the webhook grants once a year), and a missed
 * webhook silently starves a team. This command closes both: any team
 * with an ACTIVE paid subscription whose credits_renewed_at is older
 * than 32 days gets its monthly allotment. Monthly teams are renewed by
 * the webhook well inside the window, so they never double-grant —
 * matching here means the webhook was missed (self-heal).
 */
class GrantCreditRenewals extends Command
{
    protected $signature = 'credits:grant-renewals {--dry-run : List who would be granted without granting}';

    protected $description = 'Grant monthly credit allotments to active paid teams overdue for renewal (annual cycles + missed webhooks).';

    public function handle(CreditMeter $credits): int
    {
        $teams = Team::query()
            ->where('stripe_subscription_status', 'active')
            ->whereIn('plan', [Plan::Free->value, Plan::Pro->value]) // Starter + Operator; Business credits are negotiated per-contract
            ->where(function ($q): void {
                $q->whereNull('credits_renewed_at')
                    ->orWhere('credits_renewed_at', '<=', now()->subDays(32));
            })
            ->get();

        if ($teams->isEmpty()) {
            $this->components->info('No teams due for renewal.');

            return self::SUCCESS;
        }

        foreach ($teams as $team) {
            if ($this->option('dry-run')) {
                $this->line("  would grant: {$team->name} (#{$team->id}) → {$team->planObject()->monthlyCredits()} credits");

                continue;
            }

            $credits->grantMonthlyRenewal($team, ['source' => 'credits:grant-renewals']);
            $this->line("  granted: {$team->name} (#{$team->id}) → {$team->planObject()->monthlyCredits()} credits");
        }

        $this->components->info(($this->option('dry-run') ? 'Would grant' : 'Granted').' '.$teams->count().' team(s).');

        return self::SUCCESS;
    }
}
