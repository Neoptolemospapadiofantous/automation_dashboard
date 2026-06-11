<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform margin view: per-team token spend vs. plan revenue for a month.
 *
 * Cost basis = runtime_usage rollups (written by the FlowExecutor every
 * turn) × config('runtime.pricing') rates. Revenue = the team's plan
 * price (subscriptions only — top-ups appear in the credits column, not
 * revenue, to keep the number conservative). This is an OPS report —
 * customers never see token economics, they see credits.
 */
class RuntimeCosts extends Command
{
    protected $signature = 'runtime:costs {--month= : YYYY-MM (defaults to the current month)}';

    protected $description = 'Per-team LLM cost vs. plan revenue for a month (platform margin view).';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : now()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $haiku = (array) config('runtime.tiers.haiku.pricing_per_mtok', ['in' => 1.0, 'out' => 5.0]);
        $sonnet = (array) config('runtime.tiers.sonnet.pricing_per_mtok', ['in' => 3.0, 'out' => 15.0]);
        $opus = (array) config('runtime.tiers.opus.pricing_per_mtok', ['in' => 5.0, 'out' => 25.0]);

        // DB::table (not the model): SUM aliases are not model attributes.
        $usage = DB::table('runtime_usage')
            ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString().' 23:59:59'])
            ->selectRaw('team_id, SUM(turns) as turns, SUM(tokens_in) as tin, SUM(tokens_out) as tout, SUM(tokens_in_enhanced) as tin_e, SUM(tokens_out_enhanced) as tout_e, SUM(tokens_in_opus) as tin_o, SUM(tokens_out_opus) as tout_o')
            ->groupBy('team_id')
            ->get()
            ->keyBy('team_id');

        if ($usage->isEmpty()) {
            $this->components->info("No runtime usage recorded for {$month->format('Y-m')}.");

            return self::SUCCESS;
        }

        $teams = Team::query()->whereIn('id', $usage->keys())->get()->keyBy('id');

        $rows = [];
        $totals = ['turns' => 0, 'cost' => 0.0, 'revenue' => 0.0];

        foreach ($usage as $teamId => $u) {
            $team = $teams->get($teamId);
            $team = $team instanceof Team ? $team : null;
            $plan = $team?->planObject();
            $teamName = $team !== null ? $team->name : "team #{$teamId}";

            // Each tier bucket priced at its own provider rates.
            $cost = ((int) $u->tin / 1_000_000) * (float) $haiku['in']
                + ((int) $u->tout / 1_000_000) * (float) $haiku['out']
                + ((int) $u->tin_e / 1_000_000) * (float) $sonnet['in']
                + ((int) $u->tout_e / 1_000_000) * (float) $sonnet['out']
                + ((int) $u->tin_o / 1_000_000) * (float) $opus['in']
                + ((int) $u->tout_o / 1_000_000) * (float) $opus['out'];
            $revenue = (float) ($plan?->priceUsd() ?? 0);

            $creditsUsed = (int) abs(CreditTransaction::query()
                ->where('team_id', $teamId)
                ->where('reason', CreditTransaction::REASON_CONSUME_MESSAGE)
                ->whereBetween('created_at', [$month, $monthEnd])
                ->sum('amount'));

            $rows[] = [
                $teamName,
                $plan?->label() ?? '—',
                number_format((int) $u->turns),
                number_format((int) $u->tin + (int) $u->tin_e + (int) $u->tin_o),
                number_format((int) $u->tout + (int) $u->tout_e + (int) $u->tout_o),
                number_format($creditsUsed),
                '$'.number_format($cost, 2),
                '$'.number_format($revenue, 2),
                $revenue > 0 ? round((1 - $cost / $revenue) * 100).'%' : '—',
            ];

            $totals['turns'] += (int) $u->turns;
            $totals['cost'] += $cost;
            $totals['revenue'] += $revenue;
        }

        $this->components->info("Runtime costs — {$month->format('Y-m')} (per-tier provider rates from config runtime.tiers)");
        $this->table(
            ['Team', 'Plan', 'Turns', 'Tokens in', 'Tokens out', 'Credits used', 'LLM cost', 'Plan revenue', 'Margin'],
            $rows,
        );
        $this->line(sprintf(
            '  TOTAL: %s turns · $%s LLM cost · $%s plan revenue',
            number_format($totals['turns']),
            number_format($totals['cost'], 2),
            number_format($totals['revenue'], 2),
        ));

        return self::SUCCESS;
    }
}
