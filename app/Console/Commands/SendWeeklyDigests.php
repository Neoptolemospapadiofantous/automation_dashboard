<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RuntimeUsage;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WeeklyDigestEmail;
use App\Runtime\Models\KbGap;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monday-morning weekly digest to each team owner (WeeklyDigestEmail).
 *
 * Quiet-week suppression: a team with zero conversations in the window
 * gets NO email — "your agent did nothing" is churn fuel, not retention.
 * Owner-only for now; widen to admins only if someone asks.
 *
 * The per-metric definitions deliberately mirror AgentAnalyticsController
 * (escalation = meta->handoff_requested, CSAT = good/rated). The window is
 * the last N full calendar days ending at today 00:00 UTC (half-open), so
 * the numbers are stable regardless of what time the command runs.
 */
class SendWeeklyDigests extends Command
{
    protected $signature = 'teams:weekly-digest {--days=7 : Window size in days}';

    protected $description = 'Email each team owner a summary of last week\'s agent activity';

    public function handle(): int
    {
        $end = now()->startOfDay();
        $start = $end->copy()->subDays(max(1, (int) $this->option('days')));

        $teams = Team::query()
            ->with('owner')
            ->whereHas('agents', fn ($q) => $q->where('status', 'active'))
            ->get();

        $sent = 0;
        foreach ($teams as $team) {
            $owner = $team->owner;
            if (! $owner instanceof User) {
                continue;
            }

            $quiet = ! Conversation::query()
                ->where('team_id', $team->id)
                ->where('started_at', '>=', $start)
                ->where('started_at', '<', $end)
                ->exists();
            if ($quiet) {
                continue; // quiet week — say nothing
            }

            $stats = $this->stats($team, $start, $end);

            rescue(fn () => $owner->notify(new WeeklyDigestEmail($stats)), report: true);
            $sent++;
        }

        $this->info("Sent {$sent} digest(s) across {$teams->count()} team(s).");

        return self::SUCCESS;
    }

    /**
     * All numbers for one team's digest, team-wide across its agents.
     *
     * @return array<string, mixed>
     */
    protected function stats(Team $team, Carbon $start, Carbon $end): array
    {
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

        $leads = Lead::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();

        $qualified = Lead::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('status', [LeadStatus::Qualified->value, LeadStatus::Assigned->value, LeadStatus::Won->value])
            ->count();

        $won = Lead::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where('status', LeadStatus::Won->value)
            ->count();

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
            'agents' => $agents,
        ];
    }
}
