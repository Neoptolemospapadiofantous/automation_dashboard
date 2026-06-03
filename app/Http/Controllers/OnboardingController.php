<?php

namespace App\Http\Controllers;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\UpdateAgentCredentials;
use App\Lifecycle\OnboardingState;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The 3-step onboarding wizard.
 *
 * Every step reads OnboardingState to decide where the user actually belongs:
 * if they refresh in the middle, or come back tomorrow, they land at the
 * right step automatically. Each form submission goes through an action so
 * the business rules (one agent per team's first run, activation only on
 * green health, etc.) live in exactly one place.
 */
class OnboardingController extends Controller
{
    /**
     * Step 1 — explain the flow, point at Voiceflow, offer the template
     * download. Creates a draft agent on "Continue" so step 2 has something
     * to save creds against.
     */
    public function intro(Request $request): Response|RedirectResponse
    {
        if (($jump = $this->jumpIfFurtherAlong($request)) !== null) {
            return $jump;
        }

        return Inertia::render('Onboarding/Intro', [
            'team' => ['id' => $request->user()->currentTeam->id, 'name' => $request->user()->currentTeam->name],
            'template_url' => '/templates/lead-qualification.vf',
            // The Vue page changes its copy + flow based on this — managed
            // shows a one-click "Set up my agent" CTA; byok keeps the
            // 3-step "create VF account / import template / paste key" copy.
            'managed' => (bool) config('services.voiceflow.managed.enabled'),
        ]);
    }

    public function startAgent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        $team = $request->user()->currentTeam;

        // Don't create a second draft if the user re-clicks Continue.
        $existing = $team->agents()->where('status', Agent::STATUS_DRAFT)->latest()->first();
        $agent = $existing ?: (new CreateAgent())->execute($team, $data['name'] ?? 'Default agent');

        // Managed mode: CreateAgent already cloned an env and marked active.
        // Skip step 2 (no credentials to paste) and land on Done.
        if ($agent->isManaged()) {
            return redirect()->route('onboarding.done');
        }

        return redirect()->route('onboarding.connect');
    }

    /**
     * Step 2 — paste DM key + project ID. The form posts here; we run the
     * health probe (via UpdateAgentCredentials) and either land the user on
     * Done or render the same page with the failure reason.
     */
    public function connect(Request $request): Response|RedirectResponse
    {
        $state = OnboardingState::for($request->user());

        // Step 2 only makes sense once an agent row exists.
        if (in_array($state, [OnboardingState::NeedsTeam, OnboardingState::NeedsAgent], true)) {
            return redirect()->route($state->nextRoute());
        }

        if ($state === OnboardingState::Complete) {
            return redirect()->route('onboarding.done');
        }

        $agent = $this->draftAgent($request);

        return Inertia::render('Onboarding/Connect', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'voiceflow_project_id' => $agent->voiceflow_project_id,
                'voiceflow_environment' => $agent->voiceflow_environment,
                'has_api_key' => ! empty($agent->voiceflow_api_key),
                'last_health_ok' => (bool) $agent->last_health_ok,
                'webhook_url' => route('voiceflow.webhook', $agent),
                'webhook_secret' => $agent->webhook_secret,
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate(self::credentialRules(required: true), self::credentialMessages());

        $agent = $this->draftAgent($request);

        ['agent' => $updated, 'health' => $health] = (new UpdateAgentCredentials())->execute($agent, $data);

        if (! ($health['ok'] ?? false)) {
            // Stay on Connect with the failure surfaced via session flash.
            return back()->withErrors([
                'voiceflow_api_key' => $health['reason'] ?? 'Voiceflow rejected those credentials.',
            ]);
        }

        // Health passed → action transitioned the agent to ACTIVE.
        // Make sure it's the team's current_agent_id (CreateAgent already
        // did this for first-agent teams; this is a safety net for users
        // adding a second agent through the same wizard).
        $request->user()->currentTeam->forceFill(['current_agent_id' => $updated->id])->save();

        return redirect()->route('onboarding.done');
    }

    public function done(Request $request): Response|RedirectResponse
    {
        $state = OnboardingState::for($request->user());

        if ($state !== OnboardingState::Complete) {
            return redirect()->route($state->nextRoute() ?? 'onboarding.intro');
        }

        $agent = $request->user()->currentTeam->currentAgent;

        return Inertia::render('Onboarding/Done', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
        ]);
    }

    /**
     * Voiceflow credential validation, shared between this controller and
     * AgentController so the wizard and the settings page enforce identical
     * formats. Strict regexes catch the "I pasted my email by accident" class
     * of error before we even round-trip Voiceflow.
     *
     * @return array<string, array<int, string>>
     */
    public static function credentialRules(bool $required): array
    {
        $base = $required ? ['required'] : ['nullable'];

        return [
            'voiceflow_api_key' => array_merge($base, ['string', 'max:255', 'regex:/^VF\.DM\.[A-Za-z0-9]+\.[A-Za-z0-9]+$/']),
            'voiceflow_project_id' => array_merge($base, ['string', 'max:255', 'regex:/^[a-f0-9]{24}$/i']),
            'voiceflow_environment' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]{2,32}$/'],
            'voiceflow_workspace_api_key' => ['nullable', 'string', 'max:255', 'regex:/^VF\..+/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function credentialMessages(): array
    {
        return [
            'voiceflow_api_key.regex' => 'API key should look like VF.DM.xxxxxxxx.yyyy (Voiceflow Dialog Manager key).',
            'voiceflow_project_id.regex' => 'Project ID should be a 24-character hex string from your Voiceflow URL (e.g. 64f8a1b2c3d4e5f6a7b8c9d0).',
            'voiceflow_environment.regex' => 'Environment should be a short alphanumeric label (e.g. main, development).',
            'voiceflow_workspace_api_key.regex' => 'Workspace key should start with VF. (it is the broader workspace token, not the DM key).',
        ];
    }

    /**
     * The team's most-recent draft agent. The wizard creates one in step 1
     * and we keep reusing it through step 2.
     */
    protected function draftAgent(Request $request): Agent
    {
        $team = $request->user()->currentTeam;
        $agent = $team->agents()->where('status', Agent::STATUS_DRAFT)->latest()->first();

        abort_if($agent === null, 404, 'No draft agent — restart onboarding from step 1.');

        return $agent;
    }

    /**
     * If the user lands on step N but OnboardingState says they belong further
     * along, bounce them forward. Idempotent — refreshing any wizard URL
     * always lands on the right step.
     */
    protected function jumpIfFurtherAlong(Request $request): ?RedirectResponse
    {
        $state = OnboardingState::for($request->user());

        return match ($state) {
            OnboardingState::NeedsCredentials, OnboardingState::NeedsHealthCheck => redirect()->route('onboarding.connect'),
            OnboardingState::Complete => redirect()->route('onboarding.done'),
            default => null,
        };
    }
}
