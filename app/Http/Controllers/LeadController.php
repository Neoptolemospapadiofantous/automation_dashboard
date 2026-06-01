<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\LeadDeleted;
use App\Events\LeadSaved;
use App\Models\Lead;
use App\Services\LeadDelegator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(protected LeadDelegator $delegator) {}

    /**
     * The kanban board of leads for the user's current team. Supports a
     * ?mine=1 filter to show only the current user's assigned leads.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $mine = $request->boolean('mine');

        $leads = Lead::query()
            ->where('team_id', $team->id)
            ->when($mine, fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->with('assignee:id,name')
            ->latest()
            ->get();

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'statuses' => LeadStatus::board(),
            'members' => $team->allUsers()->map->only('id', 'name')->values(),
            'filters' => ['mine' => $mine],
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
        $data = $this->validateLead($request);

        $lead = Lead::create([
            ...$data,
            'team_id' => $request->user()->currentTeam->id,
        ]);

        broadcast(new LeadSaved($lead))->toOthers();

        return back();
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $lead->update($this->validateLead($request));

        broadcast(new LeadSaved($lead))->toOthers();

        return back();
    }

    /**
     * Lightweight status change used by drag-and-drop on the board.
     */
    public function updateStatus(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'status' => ['required', Rule::enum(LeadStatus::class)],
        ]);

        $lead->update($data);

        broadcast(new LeadSaved($lead))->toOthers();

        return back();
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

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
