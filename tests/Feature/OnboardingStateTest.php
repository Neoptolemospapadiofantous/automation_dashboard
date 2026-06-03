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

    public function test_team_with_unconfigured_agent_needs_credentials(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->unconfigured()->for($user->currentTeam)->create();

        $this->assertSame(OnboardingState::NeedsCredentials, OnboardingState::for($user));
    }

    public function test_team_with_configured_but_draft_agent_needs_health_check(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->draft()->for($user->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
            'last_health_ok' => false,
        ]);

        $this->assertSame(OnboardingState::NeedsHealthCheck, OnboardingState::for($user));
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

    public function test_active_agent_is_complete_even_when_env_config_missing(): void
    {
        // Regression: BEFORE this guard, an active managed agent in a
        // deployment where VOICEFLOW_MASTER_PROJECT_ID drifted/wasn't set
        // would test as not-configured and the middleware would redirect
        // every request to /onboarding/connect — the BYOK paste-keys form,
        // which is useless to a managed user. The tinker-converted dev
        // agent hit exactly this path.
        config()->set('services.voiceflow.api_key', null);
        config()->set('services.voiceflow.managed.master_project_id', null);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'mode' => Agent::MODE_MANAGED,
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_environment' => 'env-xyz',
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
