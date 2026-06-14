<?php

namespace App\Http\Controllers;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DeleteAgent;
use App\Actions\Agents\SwitchAgent;
use App\Authorization\Role;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Agent;
use App\Runtime\Contracts\Runtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agent CRUD for the team-owner UI. Every route here is thin: validate input,
 * call the matching action, redirect. Business rules live in the actions
 * (CreateAgent, SwitchAgent, DeleteAgent) so the wizard and the CLI hit the
 * same code paths.
 */
class AgentController extends Controller
{
    use AuthorizesByTeamRole;

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        return Inertia::render('Agents/Index', [
            'agents' => $team->agents()
                ->orderBy('created_at')
                ->get()
                ->map(fn (Agent $agent) => $this->presentForList($agent, $team->current_agent_id)),
        ]);
    }

    /**
     * Settings page for one agent.
     */
    public function show(Request $request, Agent $agent): Response
    {
        $this->authorize($request, $agent);

        // Per-agent activity counters — proves the agent is doing work AND
        // gives the operator a quick pulse. Cheap: each is one indexed
        // count on agent_id. Last-activity timestamp short-circuits "is
        // this agent dead?" questions without the operator having to dig
        // into Conversations.
        $activity = [
            'leads' => $agent->leads()->count(),
            'leads_qualified' => $agent->leads()->where('status', 'qualified')->count(),
            'conversations' => $agent->conversations()->count(),
            'messages' => $agent->messages()->count(),
            'last_message_at' => $agent->messages()->latest('sent_at')->value('sent_at')?->toIso8601String(),
        ];

        return Inertia::render('Agents/Show', [
            'agent' => $this->presentForSettings($agent),
            'activity' => $activity,
            // Historical prop shape kept so Vue's `webhook_url` binding
            // doesn't need a follow-up change; always null on the native
            // runtime (no inbound webhooks exist).
            'webhook_url' => null,
            'is_current' => $request->user()->currentTeam->current_agent_id === $agent->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canCreateAgent(), 'create a new agent');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // PlanLimitExceeded is handled by the global exception renderer
        // in bootstrap/app.php — JSON requests get 403, web requests get
        // a redirect-back with the flash.plan_limit payload. Don't catch
        // it here or the global handler can't pick the right shape.
        $agent = (new CreateAgent)->execute($request->user()->currentTeam, $data['name']);

        return redirect()->route('agents.show', $agent);
    }

    /**
     * Update agent name. The endpoint ONLY validates `name` — there are no
     * user-editable credentials on the native runtime.
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'update an agent');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        if (array_key_exists('name', $data)) {
            $agent->update(['name' => $data['name']]);
        }

        return back();
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);
        $this->requireCapability($request, fn (Role $r) => $r->canDeleteAgent(), 'delete an agent');

        (new DeleteAgent)->execute($agent);

        return redirect()->route('agents.index');
    }

    /**
     * Set the team's current_agent_id. Parallels Jetstream's current-team.update.
     */
    public function switchCurrent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:agents,id'],
        ]);

        $agent = Agent::findOrFail($data['agent_id']);

        try {
            (new SwitchAgent)->execute($request->user()->currentTeam, $agent);
        } catch (\InvalidArgumentException) {
            abort(403, 'That agent does not belong to your current team.');
        }

        return back();
    }

    /**
     * On-demand health probe for the settings page's "Test connection"
     * button: are the platform LLM/embedding keys present + which models
     * answer. Safe — exposes no secrets.
     */
    public function health(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize($request, $agent);

        return response()->json(app(Runtime::class)->health($agent));
    }

    protected function authorize(Request $request, Agent $agent): void
    {
        abort_unless($agent->team_id === $request->user()->currentTeam->id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentForList(Agent $agent, ?int $currentAgentId): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'status' => $agent->status,
            'is_current' => $currentAgentId === $agent->id,
            'is_configured' => $agent->isConfigured(),
            'last_health_check_at' => optional($agent->last_health_check_at)->toIso8601String(),
            'last_health_ok' => (bool) $agent->last_health_ok,
            'created_at' => $agent->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentForSettings(Agent $agent): array
    {
        // Managed projection only — there are no user-facing credentials
        // on the native runtime.
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'status' => $agent->status,
            'mode' => $agent->mode,
            'last_health_check_at' => optional($agent->last_health_check_at)->toIso8601String(),
            'last_health_ok' => (bool) $agent->last_health_ok,
        ];
    }
}
