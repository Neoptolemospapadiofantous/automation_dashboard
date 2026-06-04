<?php

namespace Tests\Feature;

use App\Lifecycle\OnboardingState;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_user_needs_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $state = OnboardingState::for($user);

        $this->assertSame(OnboardingState::NeedsAgent, $state);
        $this->assertSame('onboarding.intro', $state->nextRoute());
        $this->assertFalse($state->isComplete());
    }

    public function test_team_with_any_agent_is_complete(): void
    {
        // Phase 14 collapsed the state machine: NeedsCredentials and
        // NeedsHealthCheck were BYOK-only and are gone. ANY existing agent
        // (draft, active, with/without creds) resolves to Complete now —
        // the wizard doesn't run for users who already have an agent row.
        // Provisioning issues surface as banners on the dashboard, not as
        // redirect loops through a paste-keys form.
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->unconfigured()->for($user->currentTeam)->create();

        $this->assertSame(OnboardingState::Complete, OnboardingState::for($user));
    }

    public function test_team_with_active_agent_is_complete(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
            'last_health_ok' => true,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $state = OnboardingState::for($user->fresh());

        $this->assertSame(OnboardingState::Complete, $state);
        $this->assertNull($state->nextRoute());
        $this->assertTrue($state->isComplete());
    }

    public function test_team_with_no_current_agent_but_has_one_uses_it(): void
    {
        // current_agent_id is null but an agent exists — OnboardingState picks
        // it up so the user doesn't get stuck at NeedsAgent forever.
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($user->currentTeam)->create([
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
        ]);

        $this->assertSame(OnboardingState::Complete, OnboardingState::for($user));
    }

    public function test_active_agent_is_complete_regardless_of_isconfigured_state(): void
    {
        // Regression: BEFORE this guard, OnboardingState checked
        // isConfigured() BEFORE status==='active'. For any agent where
        // isConfigured() returned false (back when the managed-mode
        // branch checked env config that could drift, or any other
        // future condition), the middleware would redirect-loop the
        // user through /onboarding/connect — the BYOK paste-keys form,
        // which is useless to a managed user.
        //
        // The fix: trust status==='active' first. Now applies to
        // post-Phase-K managed agents too (they carry real per-row
        // credentials so isConfigured naturally returns true, but the
        // guard's also-true safety net stays).
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_environment' => 'env-xyz',
            // Intentionally null — simulates the broken state we used to
            // bounce through onboarding.connect. Now: status=active wins.
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->assertSame(OnboardingState::Complete, OnboardingState::for($user->fresh()));
    }

    public function test_managed_agent_in_any_state_does_not_route_to_byok_form(): void
    {
        // Disabled/draft managed agents are admin issues — not user-fixable
        // through the BYOK wizard. Treat as Complete so the middleware
        // doesn't redirect-loop. We'll surface real problems via banners
        // on the destination pages instead.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->draft()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'voiceflow_environment' => 'env-broken',
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $state = OnboardingState::for($user->fresh());

        $this->assertSame(OnboardingState::Complete, $state);
        $this->assertNull($state->nextRoute());
    }
}
