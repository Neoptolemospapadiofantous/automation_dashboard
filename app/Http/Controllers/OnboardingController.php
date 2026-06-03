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
        $data = $request->validate([
            'voiceflow_api_key' => ['required', 'string', 'max:255'],
            'voiceflow_project_id' => ['required', 'string', 'max:255'],
            'voiceflow_environment' => ['nullable', 'string', 'max:64'],
            'voiceflow_workspace_api_key' => ['nullable', 'string', 'max:255'],
        ]);

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
