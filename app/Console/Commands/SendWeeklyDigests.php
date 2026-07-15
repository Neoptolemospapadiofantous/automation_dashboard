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
 * The queries deliberately mirror AgentAnalyticsController's definitions
 * (escalation = meta->handoff_requested, CSAT = good/rated) so the email
 * never disagrees with the Analytics page over the same window.
 */
class SendWeeklyDigests extends Command
{
    protected $signature = 'teams:weekly-digest {--days=7 : Window size in days}';

    protected $description = 'Email each team owner a summary of last week\'s agent activity';

    public function handle(): int
    {
        $end = now();
        $start = now()->subDays(max(1, (int) $this->option('days')));

        $teams = Team::query()
            ->whereHas('agents', fn ($q) => $q->where('status', 'active'))
            ->get();

        $sent = 0;
        foreach ($teams as $team) {
            $stats = $this->stats($team, $start, $end);
            if ($stats['conversations'] === 0) {
                continue; // quiet week — say nothing
            }

            $owner = $team->owner;
            if (! $owner instanceof User) {
                continue;
            }

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
            ->whereBetween('started_at', [$start, $end])
            ->count();

        $messages = Message::query()
            ->where('team_id', $team->id)
            ->whereBetween('sent_at', [$start, $end])
            ->count();

        $leads = Lead::query()
            ->where('team_id', $team->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $qualified = Lead::query()
            ->where('team_id', $team->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', [LeadStatus::Qualified->value, LeadStatus::Assigned->value, LeadStatus::Won->value])
            ->count();

        $won = Lead::query()
            ->where('team_id', $team->id)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', LeadStatus::Won->value)
            ->count();

        $escalated = Conversation::query()
            ->where('team_id', $team->id)
            ->whereBetween('started_at', [$start, $end])
            ->where('meta->handoff_requested', true)
            ->count();

        $ratings = Conversation::query()
            ->where('team_id', $team->id)
            ->whereNotNull('rating')
            ->whereBetween('rated_at', [$start, $end])
            ->selectRaw('rating, count(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');
        $rated = (int) $ratings->sum();

        $cannedTurns = (int) RuntimeUsage::query()
            ->where('team_id', $team->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
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
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('-amount'));

        $agents = [];
        $activeAgents = $team->agents()->where('status', 'active')->get(['id', 'name']);
        if ($activeAgents->count() > 1) {
            foreach ($activeAgents as $agent) {
                $agents[] = [
                    'name' => (string) $agent->name,
                    'conversations' => Conversation::query()
                        ->where('agent_id', $agent->id)
                        ->whereBetween('started_at', [$start, $end])
                        ->count(),
                    'leads' => Lead::query()
                        ->where('agent_id', $agent->id)
                        ->whereBetween('created_at', [$start, $end])
                        ->count(),
                ];
            }
        }

        return [
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
