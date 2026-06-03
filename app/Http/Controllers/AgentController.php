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

        return Inertia::render('Agents/Show', [
            'agent' => $this->presentForSettings($agent),
            'webhook_url' => route('voiceflow.webhook', $agent),
            'is_current' => $request->user()->currentTeam->current_agent_id === $agent->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $agent = (new CreateAgent())->execute($request->user()->currentTeam, $data['name']);

        return redirect()->route('agents.show', $agent);
    }

    /**
     * Update name + credentials. The action also runs a health probe and
     * activates the agent if the probe passes — so this is the only path
     * users have to take a draft agent into active state from the UI.
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize($request, $agent);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'voiceflow_api_key' => ['nullable', 'string', 'max:255'],
            'voiceflow_project_id' => ['nullable', 'string', 'max:255'],
            'voiceflow_environment' => ['nullable', 'string', 'max:64'],
            'voiceflow_workspace_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('name', $data)) {
            $agent->update(['name' => $data['name']]);
        }

        $credentialKeys = ['voiceflow_api_key', 'voiceflow_project_id', 'voiceflow_environment', 'voiceflow_workspace_api_key'];
        $hasCredentialChange = array_intersect_key($data, array_flip($credentialKeys));

        if ($hasCredentialChange) {
            (new UpdateAgentCredentials())->execute($agent, $data);
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
        // Exposes credentials so the form can pre-fill (masked) values. The
        // $hidden list on the model blocks them from the default toArray()
        // path; this explicit projection is the one sanctioned route.
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'status' => $agent->status,
            'voiceflow_project_id' => $agent->voiceflow_project_id,
            'voiceflow_environment' => $agent->voiceflow_environment,
            'has_api_key' => ! empty($agent->voiceflow_api_key),
            'has_workspace_api_key' => ! empty($agent->voiceflow_workspace_api_key),
            'webhook_secret' => $agent->webhook_secret,
            'last_health_check_at' => optional($agent->last_health_check_at)->toIso8601String(),
            'last_health_ok' => (bool) $agent->last_health_ok,
        ];
    }
}
