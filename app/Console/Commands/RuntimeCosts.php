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
 * turn) × the per-tier rates in config('runtime.tiers.*.pricing_per_mtok'). Revenue = the team's plan
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

        // Per-tier provider rates from config; unknown tiers fall back to
        // the most expensive rates so estimates err pessimistic.
        $rates = [];
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            $rates[$key] = (array) ($tier['pricing_per_mtok'] ?? ['in' => 5.0, 'out' => 25.0]);
        }

        // DB::table (not the model): SUM aliases are not model attributes.
        $usage = DB::table('runtime_usage')
            ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString().' 23:59:59'])
            ->selectRaw('team_id, tier, SUM(turns) as turns, SUM(tokens_in) as tin, SUM(tokens_out) as tout')
            ->groupBy('team_id', 'tier')
            ->get()
            ->groupBy('team_id');

        if ($usage->isEmpty()) {
            $this->components->info("No runtime usage recorded for {$month->format('Y-m')}.");

            return self::SUCCESS;
        }

        $teams = Team::query()->whereIn('id', $usage->keys()->all())->get()->keyBy('id');

        $rows = [];
        $totals = ['turns' => 0, 'cost' => 0.0, 'revenue' => 0.0];

        foreach ($usage as $teamId => $tierRows) {
            $team = $teams->get($teamId);
            $team = $team instanceof Team ? $team : null;
            $plan = $team?->planObject();
            $teamName = $team !== null ? $team->name : "team #{$teamId}";

            // Each tier row priced at its own provider rates.
            $cost = 0.0;
            $turns = 0;
            $tin = 0;
            $tout = 0;
            foreach ($tierRows as $u) {
                $rate = $rates[(string) $u->tier] ?? ['in' => 5.0, 'out' => 25.0];
                $cost += ((int) $u->tin / 1_000_000) * (float) $rate['in']
                    + ((int) $u->tout / 1_000_000) * (float) $rate['out'];
                $turns += (int) $u->turns;
                $tin += (int) $u->tin;
                $tout += (int) $u->tout;
            }
            // Phantom-revenue guard: teams default to a plan value at signup
            // without ever paying — only count revenue for live subscriptions.
            $hasActiveSub = $team !== null && $team->getAttribute('stripe_subscription_status') === 'active';
            $revenue = $hasActiveSub ? (float) ($plan?->priceEur() ?? 0) : 0.0;

            $creditsUsed = (int) abs(CreditTransaction::query()
                ->where('team_id', $teamId)
                ->where('reason', CreditTransaction::REASON_CONSUME_MESSAGE)
                ->whereBetween('created_at', [$month, $monthEnd])
                ->sum('amount'));

            $rows[] = [
                $teamName,
                $plan?->label() ?? '—',
                number_format($turns),
                number_format($tin),
                number_format($tout),
                number_format($creditsUsed),
                '$'.number_format($cost, 2),
                '$'.number_format($revenue, 2),
                $revenue > 0 ? round((1 - $cost / $revenue) * 100).'%' : '—',
            ];

            $totals['turns'] += $turns;
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
