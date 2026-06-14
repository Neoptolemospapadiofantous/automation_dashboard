<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Financial integrity check: for every team, SUM(credit_transactions)
 * must equal credit_balance + topup_balance. Drift means a code path
 * moved credits without writing an audit row (or vice versa) — the
 * exact bug class a credits product cannot tolerate silently.
 *
 * Scheduled daily; exits non-zero on drift so the scheduler's output
 * (and ops) notice. NOTE: only sum-consistent since the expire_monthly
 * rows were introduced (2026-06-12); teams with history predating that
 * are baselined via the --baseline flag once after deploy.
 */
class ReconcileCredits extends Command
{
    protected $signature = 'credits:reconcile';

    protected $description = 'Assert SUM(credit_transactions) == credit_balance + topup_balance for every team.';

    public function handle(): int
    {
        $ledger = CreditTransaction::query()
            ->select('team_id', DB::raw('SUM(amount) as total'))
            ->groupBy('team_id')
            ->pluck('total', 'team_id');

        $drifted = [];
        foreach (Team::query()->get(['id', 'name', 'credit_balance', 'topup_balance']) as $team) {
            $expected = (int) ($ledger[$team->id] ?? 0);
            $actual = (int) $team->credit_balance + (int) $team->topup_balance;
            if ($expected !== $actual) {
                $drifted[] = sprintf('%s (#%d): ledger %d vs balance %d (Δ %+d)', $team->name, $team->id, $expected, $actual, $actual - $expected);
            }
        }

        if ($drifted === []) {
            $this->components->info('Ledger reconciles for all teams.');

            return self::SUCCESS;
        }

        $this->components->error(count($drifted).' team(s) with credit drift:');
        foreach ($drifted as $line) {
            $this->line('  '.$line);
        }

        return self::FAILURE;
    }
}
