<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\LeadDeleted;
use App\Events\LeadSaved;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Lead;
use App\Models\Team;
use App\Services\LeadDelegator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    use AuthorizesByTeamRole;

    public function __construct(protected LeadDelegator $delegator) {}

    /**
     * The kanban board of leads for the user's current team. Supports a
     * ?mine=1 filter to show only the current user's assigned leads.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }
        $filters = $this->parseFilters($request);

        // Phase G: scope by the team's current agent so the picker swaps
        // data. forAgent(null) returns no rows — a team mid-onboarding
        // gets bounced to the wizard before reaching this page anyway.
        // withCount('conversations') powers the conversation-count chip on
        // LeadCard so users can jump from a lead to its transcripts in one
        // click. Single subquery per row — cheap with the conversations
        // table's lead_id index.
        $leads = Lead::query()
            ->where('team_id', $team->id)
            ->forAgent($team->current_agent_id)
            ->when($filters['mine'], fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['source'] !== null, fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['assignee'] !== null, fn ($q) => $q->where('assigned_to', $filters['assignee']))
            ->when($filters['min_score'] !== null, fn ($q) => $q->where('score', '>=', $filters['min_score']))
            ->when($filters['since'] !== null, fn ($q) => $q->where('created_at', '>=', $filters['since']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                // SQLite LIKE is case-insensitive for ASCII; covers most
                // first-line search needs (name/email/company/phone). Migrating
                // to full-text later (or LOWER() comparisons on Postgres) is
                // a swap inside this scope only.
                $needle = '%'.$filters['q'].'%';
                $q->where(function ($q2) use ($needle) {
                    $q2->where('name', 'like', $needle)
                        ->orWhere('email', 'like', $needle)
                        ->orWhere('company', 'like', $needle)
                        ->orWhere('phone', 'like', $needle);
                });
            })
            ->with('assignee:id,name')
            ->withCount('conversations')
            ->latest()
            ->get();

        // Distinct lead sources for this team (filter dropdown). Cheap
        // groupBy — same index as the source filter applies.
        $sources = Lead::query()
            ->where('team_id', $team->id)
            ->forAgent($team->current_agent_id)
            ->whereNotNull('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->all();

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'statuses' => LeadStatus::board(),
            'members' => $team->allUsers()->map->only('id', 'name')->values(),
            'sources' => $sources,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array{
     *   mine: bool, q: string, status: ?string, source: ?string,
     *   assignee: ?int, min_score: ?int, since: ?string
     * }
     */
    protected function parseFilters(Request $request): array
    {

        $status = $request->query('status');
        $statusValid = is_string($status) && in_array(
            $status,
            array_map(fn ($s) => $s['value'], LeadStatus::board()),
            true,
        );

        $assigneeRaw = $request->query('assignee');
        $assignee = is_numeric($assigneeRaw) ? (int) $assigneeRaw : null;
        if ($assignee !== null && ! in_array($assignee, $this->memberIds($request), true)) {
            $assignee = null; // ignore cross-team ids
        }

        $minScoreRaw = $request->query('min_score');
        $minScore = is_numeric($minScoreRaw) ? max(0, min(100, (int) $minScoreRaw)) : null;

        // Since: "7d" / "30d" / "90d" relative window keys. Anything else → null.
        $sinceRaw = (string) $request->query('since', '');
        $since = match ($sinceRaw) {
            '7d' => now()->subDays(7)->toDateTimeString(),
            '30d' => now()->subDays(30)->toDateTimeString(),
            '90d' => now()->subDays(90)->toDateTimeString(),
            default => null,
        };

        return [
            'mine' => $request->boolean('mine'),
            'q' => trim((string) $request->query('q', '')),
            'status' => $statusValid ? $status : null,
            'source' => (string) $request->query('source', '') !== '' ? (string) $request->query('source') : null,
            'assignee' => $assignee,
            'min_score' => $minScore,
            'since' => $since,
            // Keep the since *key* (not the resolved datetime) so the UI
            // can re-render the chip in active state.
            'since_key' => in_array($sinceRaw, ['7d', '30d', '90d'], true) ? $sinceRaw : null,
        ];
    }

    /**
     * Per-lead detail page. Same data as the drawer, plus a conversations
     * tab below — deep-linkable so reps can share a URL with the team.
     */
    public function show(Request $request, Lead $lead): Response
    {
        $this->authorizeLead($request, $lead);

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        $lead->load('assignee:id,name');
        $lead->loadCount('conversations');

        $conversations = [];
        foreach ($lead->conversations()->latest('started_at')->limit(20)->get() as $c) {
            $conversations[] = [
                'id' => $c->getAttribute('id'),
                'started_at' => $c->getAttribute('started_at')?->toIso8601String(),
                'message_count' => $c->getAttribute('message_count'),
                'transcript_id' => $c->getAttribute('transcript_id'),
            ];
        }

        return Inertia::render('Leads/Show', [
            'lead' => $lead,
            'statuses' => LeadStatus::board(),
            'members' => $team->allUsers()->map->only('id', 'name')->values(),
            'conversations' => $conversations,
        ]);
    }

    /**
     * Assign (or auto-assign) a lead to a rep, recording the delegation.
     */
    public function assign(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'strategy' => ['required', Rule::enum(AssignmentStrategy::class)],
            'assigned_to' => ['nullable', 'integer', Rule::in($this->memberIds($request))],
        ]);

        $strategy = AssignmentStrategy::from($data['strategy']);

        $this->delegator->assign(
            lead: $lead,
            strategy: $strategy,
            byUser: $request->user(),
            toUserId: $data['assigned_to'] ?? null,
        );

        broadcast(new LeadSaved($lead->fresh()))->toOthers();

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canManageLeads(), 'create leads');

        $data = $this->validateLead($request);

        // Stamp agent_id from the current team so Phase G's agent-scoped
        // queries can find this lead. Mirrors the engine's capture_lead upsert.
        $lead = Lead::create([
            ...$data,
            'team_id' => $request->user()->currentTeam->id,
            'agent_id' => $request->user()->currentTeam->current_agent_id,
        ]);

        broadcast(new LeadSaved($lead))->toOthers();

        return back();
    }

    /**
     * Lightweight status change used by drag-and-drop on the board.
     *
     * Routes through the LeadStateMachine so the typed event chain
     * (LeadStatusChanged / LeadQualified / LeadAssigned / LeadWon / LeadLost)
     * fires consistently with the rest of the app. Invalid transitions
     * (e.g. dragging from Won back to New) throw InvalidTransition
     * which the exception handler maps to 422 with a friendly message.
     */
    public function updateStatus(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'status' => ['required', Rule::enum(LeadStatus::class)],
        ]);

        $newStatus = LeadStatus::from($data['status']);

        if ($lead->status !== $newStatus) {
            $lead->transitionTo($newStatus);
        }

        broadcast(new LeadSaved($lead->fresh()))->toOthers();

        return back();
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);
        $this->requireCapability($request, fn (Role $r) => $r->canDeleteLead(), 'delete leads');

        $teamId = $lead->team_id;
        $id = $lead->id;
        $lead->delete();

        broadcast(new LeadDeleted($id, $teamId))->toOthers();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateLead(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['nullable', 'integer', Rule::in($this->memberIds($request))],
            'notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * Update the freeform notes on a lead. Used by the side-drawer detail
     * view — operators jot follow-up context here.
     */
    public function updateNotes(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $lead->forceFill(['notes' => $data['notes'] ?? null])->save();

        return response()->json(['ok' => true, 'notes' => $lead->notes]);
    }

    /**
     * Ensure the lead belongs to the user's current team.
     */
    protected function authorizeLead(Request $request, Lead $lead): void
    {
        abort_unless($lead->team_id === $request->user()->currentTeam->id, 403);
    }

    /**
     * @return array<int, int>
     */
    protected function memberIds(Request $request): array
    {
        return $request->user()->currentTeam->allUsers()->pluck('id')->all();
    }
}
