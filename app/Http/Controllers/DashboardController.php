<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\RuntimeSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Team-scoped analytics overview. Numbers are computed live; the page also
     * subscribes to broadcasts so counters tick without a reload.
     */
    public function __invoke(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403);

        return Inertia::render('Dashboard', [
            ...$this->stats($team->id, $team->current_agent_id),
            'setup' => $this->setupChecklist($team->current_agent_id),
            'series' => $this->series($team->id, $team->current_agent_id),
            'queue' => $this->queue($team->id, $team->current_agent_id),
            'activity' => $this->activity($team->id, $team->current_agent_id),
        ]);
    }

    /**
     * Seven-day daily counts + a delta against the previous seven days, per
     * KPI tile. Total leads and conversations bucket by created_at; the
     * status tiles bucket leads currently IN that status by the day they
     * were last touched — the closest thing to "entered the stage" without a
     * status-history table, and honest enough for a sparkline.
     *
     * @return array<string, array{points: list<int>, delta: int}>
     */
    protected function series(int $teamId, ?int $agentId): array
    {
        $days = collect(range(6, 0))->map(fn (int $d) => now()->subDays($d)->toDateString());
        $from = now()->subDays(6)->startOfDay();

        $bucket = function (Builder $q, string $column) use ($days, $from): array {
            $rows = (clone $q)->where($column, '>=', $from)
                ->selectRaw("DATE({$column}) as d, COUNT(*) as c")
                ->groupBy('d')
                ->pluck('c', 'd');

            return $days->map(fn (string $day) => (int) ($rows[$day] ?? 0))->values()->all();
        };
        $delta = function (Builder $q, string $column): int {
            $last = (clone $q)->where($column, '>=', now()->subDays(7))->count();
            $prev = (clone $q)->whereBetween($column, [now()->subDays(14), now()->subDays(7)])->count();

            return $last - $prev;
        };

        $leads = Lead::where('team_id', $teamId)->forAgent($agentId);
        $inStatus = fn (LeadStatus $s): Builder => (clone $leads)->where('status', $s->value);
        $convos = Conversation::where('team_id', $teamId)->forAgent($agentId);

        return [
            'total_leads' => ['points' => $bucket($leads, 'created_at'), 'delta' => $delta($leads, 'created_at')],
            'qualified' => ['points' => $bucket($inStatus(LeadStatus::Qualified), 'updated_at'), 'delta' => $delta($inStatus(LeadStatus::Qualified), 'updated_at')],
            'assigned' => ['points' => $bucket($inStatus(LeadStatus::Assigned), 'updated_at'), 'delta' => $delta($inStatus(LeadStatus::Assigned), 'updated_at')],
            'won' => ['points' => $bucket($inStatus(LeadStatus::Won), 'updated_at'), 'delta' => $delta($inStatus(LeadStatus::Won), 'updated_at')],
            'conversations' => ['points' => $bucket($convos, 'created_at'), 'delta' => $delta($convos, 'created_at')],
        ];
    }

    /**
     * Conversation timestamps are plain strings on the model; leads cast
     * theirs. One normaliser so every `at` below is ISO-8601 or null.
     */
    protected function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }
        $text = trim((string) $value);

        return $text === '' ? null : CarbonImmutable::parse($text)->toIso8601String();
    }

    /**
     * "Needs you": the things a human should look at now, in priority order —
     * visitors waiting on a handoff, unassigned high-score leads, then leads
     * nobody has contacted in five days. Six rows at most.
     *
     * @return list<array{kind: string, title: string, detail: ?string, score: ?int, at: ?string, href: string, action: string}>
     */
    protected function queue(int $teamId, ?int $agentId): array
    {
        $handoffs = Conversation::where('team_id', $teamId)->forAgent($agentId)
            ->where('status', 'active')
            // MySQL and MariaDB disagree on JSON booleans; unquoted text is
            // 'true' on both.
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.handoff_requested')) IN ('true', '1')")
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.human_takeover')), 'false') NOT IN ('true', '1')")
            ->orderByDesc('last_message_at')
            ->limit(3)
            ->get()
            ->map(fn (Conversation $c): array => [
                'kind' => 'handoff',
                'title' => 'Visitor asked for a human',
                'detail' => (string) ($c->messages()->where('role', 'user')->orderByDesc('id')->value('text') ?? ''),
                'score' => null,
                'at' => $this->iso($c->last_message_at),
                'href' => route('conversations.show', $c),
                'action' => 'Take over',
            ]);

        $unassigned = Lead::where('team_id', $teamId)->forAgent($agentId)
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Qualified->value])
            ->whereNull('assigned_to')
            ->orderByDesc('score')
            ->limit(3)
            ->get()
            ->map(fn (Lead $l): array => [
                'kind' => 'unassigned',
                'title' => (string) $l->name,
                'detail' => (string) ($l->company ?? ''),
                'score' => (int) $l->score,
                'at' => $this->iso($l->created_at),
                'href' => route('leads.show', $l),
                'action' => 'Assign',
            ]);

        $stale = Lead::where('team_id', $teamId)->forAgent($agentId)
            ->whereIn('status', [LeadStatus::Assigned->value, LeadStatus::Qualified->value])
            ->where(fn (Builder $q) => $q->whereNull('last_contacted_at')->orWhere('last_contacted_at', '<', now()->subDays(5)))
            ->orderBy('last_contacted_at')
            ->limit(2)
            ->get()
            ->map(fn (Lead $l): array => [
                'kind' => 'stale',
                'title' => (string) $l->name,
                'detail' => $l->last_contacted_at ? 'no contact for '.$l->last_contacted_at->diffInDays(now()).' days' : 'never contacted',
                'score' => (int) $l->score,
                'at' => $this->iso($l->last_contacted_at ?? $l->created_at),
                'href' => route('leads.show', $l),
                'action' => 'Open',
            ]);

        return $handoffs->concat($unassigned)->concat($stale)->take(6)->values()->all();
    }

    /**
     * The last six things that happened to this agent, newest first — leads
     * captured, conversations ended, knowledge added, credits granted.
     *
     * @return list<array{kind: string, text: string, at: string, href: ?string}>
     */
    protected function activity(int $teamId, ?int $agentId): array
    {
        $events = collect();

        foreach (Lead::where('team_id', $teamId)->forAgent($agentId)->latest()->limit(5)->get() as $l) {
            $events->push(['kind' => 'lead', 'text' => 'Lead captured · '.$l->name, 'at' => $this->iso($l->created_at), 'href' => route('leads.show', $l)]);
        }
        foreach (Conversation::where('team_id', $teamId)->forAgent($agentId)->whereNotNull('ended_at')->orderByDesc('ended_at')->limit(5)->get() as $c) {
            $events->push(['kind' => 'conversation', 'text' => 'Conversation ended · '.$c->message_count.' messages', 'at' => $this->iso($c->ended_at), 'href' => route('conversations.show', $c)]);
        }
        if ($agentId !== null) {
            foreach (KbDocument::where('agent_id', $agentId)->latest()->limit(5)->get() as $d) {
                $events->push(['kind' => 'knowledge', 'text' => 'Knowledge updated · '.$d->title, 'at' => $this->iso($d->created_at), 'href' => route('knowledge.index')]);
            }
        }
        foreach (CreditTransaction::where('team_id', $teamId)->whereIn('reason', [CreditTransaction::REASON_GRANT_RENEWAL, CreditTransaction::REASON_GRANT_TOPUP])->latest()->limit(3)->get() as $t) {
            $events->push(['kind' => 'credits', 'text' => 'Credits granted · +'.number_format((int) $t->amount), 'at' => $this->iso($t->created_at), 'href' => route('billing.index')]);
        }

        return $events
            ->filter(fn (array $e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * Live setup checklist for the current agent — the in-app companion to
     * docs/agent-lifecycle.md. Each step is derived from real data (never
     * stored), so it self-completes as the operator works and the card
     * disappears when everything is done. All checks are cheap indexed
     * exists() queries.
     *
     * @return array{complete: bool, steps: list<array{key: string, done: bool}>}
     */
    protected function setupChecklist(?int $agentId): array
    {
        // Engine connection (ANTHROPIC/OPENAI keys) is provisioned by us, not
        // the customer — so it's intentionally NOT a user-facing setup step.
        $steps = [
            ['key' => 'knowledge', 'done' => $agentId !== null && KbDocument::where('agent_id', $agentId)->exists()],
            ['key' => 'behavior', 'done' => $agentId !== null && AgentConfigVersion::query()
                ->where('agent_id', $agentId)
                ->where('status', AgentConfigVersion::STATUS_PUBLISHED)
                ->exists()],
            ['key' => 'chat', 'done' => $agentId !== null && Conversation::where('agent_id', $agentId)->exists()],
            // The embed widget stamps visitor ids with the embed- prefix, so
            // 'a session from the website exists' === 'the snippet is live'.
            ['key' => 'install', 'done' => $agentId !== null && RuntimeSession::query()
                ->where('agent_id', $agentId)
                ->where('visitor_id', 'like', 'embed-%')
                ->exists()],
            ['key' => 'lead', 'done' => $agentId !== null && Lead::where('agent_id', $agentId)->exists()],
        ];

        return [
            'complete' => ! in_array(false, array_column($steps, 'done'), true),
            'steps' => $steps,
        ];
    }

    /**
     * Phase G: counters are scoped to the team's current agent so the
     * dashboard reflects what the user is currently working in. Switching
     * agents swaps every number on the page.
     *
     * @return array<string, mixed>
     */
    protected function stats(int $teamId, ?int $agentId): array
    {
        $byStatus = Lead::where('team_id', $teamId)
            ->forAgent($agentId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $totalLeads = (int) $byStatus->sum();
        $won = (int) ($byStatus[LeadStatus::Won->value] ?? 0);
        $lost = (int) ($byStatus[LeadStatus::Lost->value] ?? 0);
        $qualified = (int) ($byStatus[LeadStatus::Qualified->value] ?? 0);
        $assigned = (int) ($byStatus[LeadStatus::Assigned->value] ?? 0);

        $decided = $won + $lost;
        $conversionRate = $decided > 0 ? round(($won / $decided) * 100, 1) : 0.0;

        // Funnel in pipeline order.
        $funnel = collect(LeadStatus::board())->map(fn ($s) => [
            'label' => $s['label'],
            'value' => $s['value'],
            'color' => $s['color'],
            'count' => (int) ($byStatus[$s['value']] ?? 0),
        ])->values();

        // Per-rep open load (assigned + qualified), scoped to the current
        // agent. SQL aggregation + one name lookup — no model hydration.
        $repCounts = Lead::where('team_id', $teamId)
            ->forAgent($agentId)
            ->whereIn('status', [LeadStatus::Assigned->value, LeadStatus::Qualified->value])
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        $repNames = User::whereIn('id', $repCounts->keys())->pluck('name', 'id');

        $repLoad = $repCounts
            ->map(fn ($count, $userId): array => [
                'name' => (string) ($repNames[$userId] ?? 'Unknown'),
                'count' => (int) $count,
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'stats' => [
                'total_leads' => $totalLeads,
                'qualified' => $qualified,
                'assigned' => $assigned,
                'won' => $won,
                'lost' => $lost,
                'conversion_rate' => $conversionRate,
                'conversations' => Conversation::where('team_id', $teamId)->forAgent($agentId)->count(),
                'messages' => Message::where('team_id', $teamId)->forAgent($agentId)->count(),
                'active_conversations' => Conversation::where('team_id', $teamId)->forAgent($agentId)->where('status', 'active')->count(),
            ],
            'funnel' => $funnel,
            'rep_load' => $repLoad,
        ];
    }
}
