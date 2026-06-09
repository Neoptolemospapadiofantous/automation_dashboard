<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-agent analytics dashboard. Time-series + funnel + source breakdown
 * for one agent. Server-computed so the front end just renders SVG
 * sparklines from arrays of numbers — no chart library required.
 *
 * Window selector: 7d / 30d / 90d via ?window=N. Default 30 days.
 * All series are bucketed by day in the team's UTC clock for now;
 * timezone-per-team is a follow-up.
 */
class AgentAnalyticsController extends Controller
{
    public function show(Request $request, Agent $agent): Response
    {
        $this->authorize($request, $agent);

        $days = $this->resolveWindow($request);
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();

        return Inertia::render('Agents/Analytics', [
            'agent' => [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'status' => $agent->status,
            ],
            'window' => [
                'days' => $days,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'series' => [
                'conversations' => $this->dailySeries(Conversation::class, 'started_at', $agent->id, $start, $end),
                'messages' => $this->dailySeries(Message::class, 'sent_at', $agent->id, $start, $end),
                'leads' => $this->dailySeries(Lead::class, 'created_at', $agent->id, $start, $end),
                'credits' => $this->creditsSeries($agent->id, $start, $end),
            ],
            'totals' => $this->totals($agent->id, $start, $end),
            'funnel' => $this->funnel($agent->id, $start, $end),
            'sources' => $this->sources($agent->id, $start, $end),
            'hourly' => $this->hourlyActivity($agent->id, $start, $end),
        ]);
    }

    protected function resolveWindow(Request $request): int
    {
        $raw = (int) $request->query('window', '30');

        return in_array($raw, [7, 30, 90], true) ? $raw : 30;
    }

    /**
     * Generic per-day count for an agent-scoped model. Returns an array
     * of {date: 'YYYY-MM-DD', count: N} for every day in the window —
     * gaps filled with 0 so sparklines render straight lines through quiet days.
     *
     * @param  class-string  $modelClass
     * @return array<int, array{date: string, count: int}>
     */
    protected function dailySeries(string $modelClass, string $dateColumn, int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $modelClass::query()
            ->where('agent_id', $agentId)
            ->whereBetween($dateColumn, [$start, $end])
            ->selectRaw("date({$dateColumn}) as d, count(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDays($start, $end, fn (string $d) => (int) ($rows[$d] ?? 0));
    }

    /**
     * Credit consumption per day (positive number = credits spent).
     *
     * @return array<int, array{date: string, count: int}>
     */
    protected function creditsSeries(int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = CreditTransaction::query()
            ->where('agent_id', $agentId)
            ->where('amount', '<', 0)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('date(created_at) as d, sum(-amount) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDays($start, $end, fn (string $d) => (int) ($rows[$d] ?? 0));
    }

    /**
     * Aggregate totals across the window — these go in the headline cards.
     *
     * @return array<string, int|float>
     */
    protected function totals(int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $conversations = Conversation::query()
            ->where('agent_id', $agentId)
            ->whereBetween('started_at', [$start, $end])
            ->count();

        $messages = Message::query()
            ->where('agent_id', $agentId)
            ->whereBetween('sent_at', [$start, $end])
            ->count();

        $leads = Lead::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $qualified = Lead::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', [
                LeadStatus::Qualified->value,
                LeadStatus::Assigned->value,
                LeadStatus::Won->value,
            ])
            ->count();

        $won = Lead::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', LeadStatus::Won->value)
            ->count();

        $creditsSpent = (int) CreditTransaction::query()
            ->where('agent_id', $agentId)
            ->where('amount', '<', 0)
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('-amount'));

        $captureRate = $conversations > 0 ? round(($leads / $conversations) * 100, 1) : 0.0;
        $qualifyRate = $leads > 0 ? round(($qualified / $leads) * 100, 1) : 0.0;
        $winRate = $qualified > 0 ? round(($won / $qualified) * 100, 1) : 0.0;

        return [
            'conversations' => $conversations,
            'messages' => $messages,
            'leads' => $leads,
            'qualified' => $qualified,
            'won' => $won,
            'credits_spent' => $creditsSpent,
            'capture_rate' => $captureRate,
            'qualify_rate' => $qualifyRate,
            'win_rate' => $winRate,
        ];
    }

    /**
     * Lead pipeline funnel — counts per status, in board order.
     *
     * @return array<int, array{label: string, status: string, count: int, color: string}>
     */
    protected function funnel(int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = Lead::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = [];
        foreach (LeadStatus::board() as $row) {
            $out[] = [
                'label' => $row['label'],
                'status' => $row['value'],
                'count' => (int) ($counts[$row['value']] ?? 0),
                'color' => $row['color'],
            ];
        }

        return $out;
    }

    /**
     * Top lead sources for the window — which channels produce leads.
     *
     * @return array<int, array{source: string, count: int}>
     */
    protected function sources(int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Lead::query()
            ->where('agent_id', $agentId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('source')
            ->selectRaw('source, count(*) as src_count')
            ->groupBy('source')
            ->orderByDesc('src_count')
            ->limit(8)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'source' => (string) ($row->getAttribute('source') ?? ''),
                'count' => (int) ($row->getAttribute('src_count') ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Hour-of-day activity heatmap — when conversations are happening.
     * Returns 24 buckets (0-23), each with a count.
     *
     * @return array<int, int>
     */
    protected function hourlyActivity(int $agentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Conversation::query()
            ->where('agent_id', $agentId)
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw('strftime("%H", started_at) as h, count(*) as c')
            ->groupBy('h')
            ->pluck('c', 'h');

        $out = array_fill(0, 24, 0);
        foreach ($rows as $hour => $count) {
            $h = (int) $hour;
            if ($h >= 0 && $h < 24) {
                $out[$h] = (int) $count;
            }
        }

        return $out;
    }

    /**
     * @param  callable(string):int  $valueFor
     * @return array<int, array{date: string, count: int}>
     */
    protected function fillDays(CarbonImmutable $start, CarbonImmutable $end, callable $valueFor): array
    {
        $out = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end)) {
            $d = $cursor->toDateString();
            $out[] = ['date' => $d, 'count' => $valueFor($d)];
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    protected function authorize(Request $request, Agent $agent): void
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team && $agent->team_id === $team->id, 403);
    }
}
