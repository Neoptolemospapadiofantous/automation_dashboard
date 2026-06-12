<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily token-spend tripwire: prices yesterday's runtime_usage rollups
 * at the per-tier provider rates and fails if the platform-wide total
 * crossed the SLA ceiling. The manual margin report (runtime:costs)
 * tells you WHO; this scheduled check makes sure you LOOK.
 */
class RuntimeSpendCheck extends Command
{
    protected $signature = 'runtime:spend-check {--date= : YYYY-MM-DD (defaults to yesterday)}';

    protected $description = 'Fail when a day\'s platform-wide LLM spend crossed the SLA ceiling.';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $ceiling = (float) config('sla.spend.daily_ceiling_usd');

        $rows = DB::table('runtime_usage')
            ->whereDate('date', $date)
            ->selectRaw('tier, SUM(tokens_in) as tin, SUM(tokens_out) as tout, SUM(turns) as turns')
            ->groupBy('tier')
            ->get();

        $cost = 0.0;
        $turns = 0;
        foreach ($rows as $row) {
            $rate = (array) config("runtime.tiers.{$row->tier}.pricing_per_mtok", ['in' => 5.0, 'out' => 25.0]);
            $cost += ((int) $row->tin / 1_000_000) * (float) $rate['in']
                + ((int) $row->tout / 1_000_000) * (float) $rate['out'];
            $turns += (int) $row->turns;
        }

        $summary = sprintf('%s: %s turns · $%s spend (ceiling $%s)', $date, number_format($turns), number_format($cost, 2), number_format($ceiling, 2));

        if ($cost > $ceiling) {
            $this->components->error('SPEND CEILING BREACHED — '.$summary.'. Run runtime:costs to find the team.');

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }
}
