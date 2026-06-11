<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\RuntimeSession;
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
        ]);
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
        $engineReady = (string) config('runtime.llm.anthropic.api_key') !== ''
            && (string) config('runtime.embeddings.openai_api_key') !== '';

        $steps = [
            ['key' => 'engine', 'done' => $engineReady],
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
