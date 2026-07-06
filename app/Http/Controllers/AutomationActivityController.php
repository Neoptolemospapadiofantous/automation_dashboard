<?php

namespace App\Http\Controllers;

use App\Models\AutomationRun;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automation activity view (phase-16 remaining work #2) — surfaces the
 * automation_runs audit log for the current agent: every agent → n8n
 * invocation with its status, latency, credits charged, and a trimmed
 * request/response snapshot. Read-only; the rows are written by the runtime.
 *
 * Agent-scoped (like Conversations) so switching the current agent swaps the
 * list, and team-scoped so there's no cross-tenant peek.
 */
class AutomationActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $teamId = $team instanceof Team ? $team->id : 0;
        $agentId = $team instanceof Team ? $team->current_agent_id : null;

        $statusFilter = trim((string) $request->input('status', ''));
        $actionFilter = trim((string) $request->input('action', ''));

        $base = AutomationRun::query()
            ->where('team_id', $teamId)
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($agentId === null, fn ($q) => $q->whereRaw('1 = 0'));

        $runs = (clone $base)
            ->when(
                in_array($statusFilter, self::STATUSES, true),
                fn ($q) => $q->where('status', $statusFilter),
            )
            // The filter value is the action's stable identity: its config
            // ULID (action_id), or the name for rows written before ids
            // existed — so a renamed action's history stays one group.
            ->when($actionFilter !== '', fn ($q) => $q->whereRaw('coalesce(action_id, action) = ?', [$actionFilter]))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AutomationRun $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'mode' => $r->mode,
                'status' => $r->status,
                'http_status' => $r->http_status,
                'duration_ms' => $r->duration_ms,
                'credits_charged' => $r->credits_charged,
                'error' => $r->error,
                'request' => $r->request,
                'response' => $r->response,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        // Agent-scoped lifetime rollups for the summary cards.
        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) $byStatus->sum();
        $success = (int) ($byStatus[AutomationRun::STATUS_SUCCESS] ?? 0);

        // One filter option per action identity (action_id, or name for
        // pre-id rows), labelled with the name from its most recent run — a
        // renamed action shows up once, under its current name.
        $latestRunIds = (clone $base)
            ->selectRaw('max(id) as id')
            ->groupByRaw('coalesce(action_id, action)')
            ->pluck('id');
        $actionOptions = AutomationRun::query()
            ->whereIn('id', $latestRunIds)
            ->orderBy('action')
            ->get(['action', 'action_id'])
            ->map(fn (AutomationRun $r): array => [
                'value' => $r->action_id ?? $r->action,
                'label' => $r->action,
            ])
            ->values()
            ->all();

        return Inertia::render('Agents/Activity', [
            'runs' => $runs,
            'filters' => ['status' => $statusFilter, 'action' => $actionFilter],
            'statuses' => self::STATUSES,
            'actionOptions' => $actionOptions,
            'summary' => [
                'total' => $total,
                'success' => $success,
                'success_rate' => $total > 0 ? round($success / $total * 100) : null,
                'credits_charged' => (int) (clone $base)->sum('credits_charged'),
            ],
        ]);
    }

    private const STATUSES = [
        AutomationRun::STATUS_PENDING,
        AutomationRun::STATUS_SUCCESS,
        AutomationRun::STATUS_FAILED,
        AutomationRun::STATUS_BLOCKED,
        AutomationRun::STATUS_TIMEOUT,
        AutomationRun::STATUS_OUT_OF_CREDITS,
    ];
}
