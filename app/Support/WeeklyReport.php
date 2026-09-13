<?php

namespace App\Support;

use App\Enums\LeadStatus;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RuntimeUsage;
use App\Models\Team;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\KbGap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One team's "week in review" numbers, team-wide across its agents.
 *
 * Built once, read twice: teams:weekly-digest mails it on Monday, and the
 * shareable /report/{token} page renders the same block live for whoever
 * holds the link — so the email and the page can never disagree.
 *
 * The per-metric definitions mirror AgentAnalyticsController (escalation =
 * meta->handoff_requested, CSAT = good/rated). The window is the last N
 * full calendar days ending at today 00:00 UTC (half-open), so the numbers
 * are stable regardless of what time they are computed.
 */
class WeeklyReport
{
    /**
     * @return array<string, mixed>
     */
    public function stats(Team $team, int $days = 7, ?Carbon $end = null): array
    {
        $end = ($end ?? now())->copy()->startOfDay();
        $start = $end->copy()->subDays(max(1, $days));

        $conversations = Conversation::query()
            ->where('team_id', $team->id)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->count();

        $messages = Message::query()
            ->where('team_id', $team->id)
            ->where('sent_at', '>=', $start)
            ->where('sent_at', '<', $end)
            ->count();

        $leadsInWindow = Lead::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        $leads = (clone $leadsInWindow)->count();
        $qualified = (clone $leadsInWindow)
            ->whereIn('status', [LeadStatus::Qualified->value, LeadStatus::Assigned->value, LeadStatus::Won->value])
            ->count();
        $won = (clone $leadsInWindow)->where('status', LeadStatus::Won->value)->count();

        $escalated = Conversation::query()
            ->where('team_id', $team->id)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->where('meta->handoff_requested', true)
            ->count();

        $ratings = Conversation::query()
            ->where('team_id', $team->id)
            ->whereNotNull('rating')
            ->where('rated_at', '>=', $start)
            ->where('rated_at', '<', $end)
            ->selectRaw('rating, count(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');
        $rated = (int) $ratings->sum();

        // date is a whole-day bucket: the window's days are start..end-1 inclusive.
        $cannedTurns = (int) RuntimeUsage::query()
            ->where('team_id', $team->id)
            ->whereBetween('date', [$start->toDateString(), $end->copy()->subDay()->toDateString()])
            ->sum('canned_turns');

        $agentIds = $team->agents()->pluck('id');

        $gaps = KbGap::query()
            ->whereIn('agent_id', $agentIds)
            ->orderByDesc('asked_count')
            ->limit(3)
            ->get()
            ->map(fn (KbGap $g) => [
                'question' => (string) $g->question,
                'asked_count' => (int) $g->asked_count,
            ])
            ->all();

        $staleLeads = Lead::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Qualified->value, LeadStatus::Assigned->value])
            ->whereNull('last_contacted_at')
            ->count();

        $creditsUsed = (int) CreditTransaction::query()
            ->where('team_id', $team->id)
            ->where('amount', '<', 0)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->sum(DB::raw('-amount'));

        // URL documents whose page changed and were re-ingested this week
        // (knowledge:refresh-urls) — the answers moved with the site.
        $refreshedDocs = KbDocument::query()
            ->whereIn('agent_id', $agentIds)
            ->where('refreshed_at', '>=', $start)
            ->where('refreshed_at', '<', $end)
            ->count();

        // Daily conversation counts across the window, oldest first, for
        // the report page's small bars.
        $byDay = [];
        foreach (Conversation::query()
            ->where('team_id', $team->id)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->pluck('started_at') as $startedAt) {
            $key = Carbon::parse((string) $startedAt)->toDateString();
            $byDay[$key] = ($byDay[$key] ?? 0) + 1;
        }
        $daily = [];
        for ($d = $start->copy(); $d->lessThan($end); $d->addDay()) {
            $daily[] = ['date' => $d->toDateString(), 'conversations' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }

        // All agents, not just active ones — a disabled agent's traffic is in
        // the team totals, so the per-agent lines must include it to sum up.
        $agents = [];
        $teamAgents = $team->agents()->get(['id', 'name']);
        if ($teamAgents->count() > 1) {
            foreach ($teamAgents as $agent) {
                $agents[] = [
                    'name' => (string) $agent->name,
                    'conversations' => Conversation::query()
                        ->where('agent_id', $agent->id)
                        ->where('started_at', '>=', $start)
                        ->where('started_at', '<', $end)
                        ->count(),
                    'leads' => Lead::query()
                        ->where('agent_id', $agent->id)
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end)
                        ->count(),
                ];
            }
        }

        return [
            'team' => (string) $team->name,
            'window' => ['start' => $start->toDateString(), 'end' => $end->copy()->subDay()->toDateString(), 'days' => $days],
            'conversations' => $conversations,
            'messages' => $messages,
            'leads' => $leads,
            'qualified' => $qualified,
            'won' => $won,
            'escalated' => $escalated,
            'escalation_rate' => $conversations > 0 ? round(($escalated / $conversations) * 100, 1) : 0.0,
            'csat' => $rated > 0 ? round(((int) ($ratings['good'] ?? 0) / $rated) * 100, 1) : null,
            'canned_turns' => $cannedTurns,
            'gaps' => $gaps,
            'stale_leads' => $staleLeads,
            'credits_used' => $creditsUsed,
            'credits_remaining' => (int) $team->credit_balance + (int) $team->topup_balance,
            'refreshed_docs' => $refreshedDocs,
            'daily' => $daily,
            'agents' => $agents,
            'share_url' => $team->report_token !== null ? route('report.public', $team->report_token) : null,
        ];
    }

    /**
     * A fresh unguessable token for the public report link.
     */
    public static function newToken(): string
    {
        return Str::lower(Str::random(40));
    }
}
