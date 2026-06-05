<?php

namespace App\Http\Controllers;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DeleteAgent;
use App\Actions\Agents\RotateWebhookSecret;
use App\Actions\Agents\SwitchAgent;
use App\Actions\Agents\UpdateAgentCredentials;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agent CRUD for the team-owner UI. Every route here is thin: validate input,
 * call the matching action, redirect. Business rules live in the actions
 * (CreateAgent, UpdateAgentCredentials, RotateWebhookSecret, SwitchAgent,
 * DeleteAgent) so the wizard and the CLI hit the same code paths.
 */
class AgentController extends Controller
{
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
     * Settings page for one agent. Shows the webhook URL + secret (sensitive
     * fields surfaced explicitly here — never via the default $hidden serializer).
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
            // Webhook URL only matters for BYOK — the user has to paste it
            // into their own Voiceflow Custom Action. In managed mode we
            // configure that on the master template ourselves, so the URL
            // is an implementation detail (and surfacing it would invite
            // the user to misconfigure something they don't own).
            // Phase 14: always null now that BYOK left the product surface;
            // kept the field shape so Vue's `webhook_url` prop binding
            // doesn't need a follow-up change.
            'webhook_url' => null,
            'is_current' => $request->user()->currentTeam->current_agent_id === $agent->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // PlanLimitExceeded is handled by the global exception renderer
        // in bootstrap/app.php — JSON requests get 403, web requests get
        // a redirect-back with the flash.plan_limit payload. Don't catch
        // it here or the global handler can't pick the right shape.
        $agent = (new CreateAgent())->execute($request->user()->currentTeam, $data['name']);

        return redirect()->route('agents.show', $agent);
    }

    /**
     * Update agent name. Credentials are no longer user-editable — Phase 14
     * removed BYOK from the product surface, so all user-flow agents are
     * managed (credentials minted by the pool, never touched by the user).
     *
     * The endpoint ONLY validates `name`. Any credential field in the
     * payload is silently dropped — a clever curl can't sneak credential
     * writes regardless of the agent's mode. If ops wires a BYOK agent
     * for a Custom-tier customer, ops updates credentials via tinker
     * (the UpdateAgentCredentials action is still importable).
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);

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

        (new DeleteAgent())->execute($agent);

        return redirect()->route('agents.index');
    }

    /**
     * Rotate the per-agent webhook secret. The new value is immediately
     * required — the user must update their Voiceflow Custom Action header
     * to match. We surface the fresh secret in a flash message.
     */
    public function rotateSecret(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);

        $rotated = (new RotateWebhookSecret())->execute($agent);

        return back()->with('flash.webhook_secret_rotated', $rotated->webhook_secret);
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
            (new SwitchAgent())->execute($request->user()->currentTeam, $agent);
        } catch (\InvalidArgumentException) {
            abort(403, 'That agent does not belong to your current team.');
        }

        return back();
    }

    /**
     * Run an on-demand health probe and report the result. The
     * UpdateAgentCredentials action does this implicitly on save, but the
     * settings page also wants a "Test connection" button that doesn't
     * require resaving.
     */
    public function health(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize($request, $agent);

        // Re-run the existing pipeline so activation rules (draft → active on
        // green) stay in exactly one place.
        ['health' => $health] = (new UpdateAgentCredentials())->execute($agent, []);

        return response()->json($health);
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
        // Phase 14: BYOK is gone from the product surface, so the settings
        // page only ever renders the managed projection. Credential fields
        // + webhook URL/secret are intentionally absent — the user neither
        // set them nor needs to touch them.
        //
        // Ops-wired BYOK agents (Custom-tier one-offs) get the same
        // projection here; their credentials are administered out-of-band
        // via tinker, not via the UI.
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
