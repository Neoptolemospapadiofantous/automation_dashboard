<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAgent;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The wizard tests EXPLICITLY want the RequireAgent middleware active —
     * the global setUp disables it for the broader suite.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withMiddleware(RequireAgent::class);
    }

    public function test_user_without_agent_is_redirected_from_app_routes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('onboarding.intro'));

        $this->actingAs($user)->get('/leads')
            ->assertRedirect(route('onboarding.intro'));
    }

    public function test_user_with_active_agent_passes_middleware(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'status' => Agent::STATUS_ACTIVE,
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
            'last_health_ok' => true,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_onboarding_routes_themselves_are_not_gated(): void
    {
        // The wizard must be reachable WITHOUT an agent — otherwise the user
        // can never escape the redirect loop.
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('onboarding.intro'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Intro'));
    }

    public function test_agents_pages_are_reachable_during_onboarding(): void
    {
        // Users mid-onboarding should still reach /agents (so they can manage
        // a partly-configured agent) without bouncing to the wizard root.
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->draft()->for($user->currentTeam)->create();

        $this->actingAs($user)->get(route('agents.index'))->assertOk();
    }

    public function test_intro_post_provisions_a_managed_agent_and_jumps_to_done(): void
    {
        // Phase 14: the wizard collapsed to a single step. POSTing to
        // onboarding.start now allocates from the pool, marks the agent
        // active, and redirects straight to Done. No Connect step exists.
        \App\Models\VoiceflowProjectPoolEntry::factory()->create();
        config()->set('services.voiceflow.managed.enabled', true);

        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), ['name' => 'My first agent'])
            ->assertRedirect(route('onboarding.done'));

        $this->assertDatabaseHas('agents', [
            'team_id' => $user->currentTeam->id,
            'name' => 'My first agent',
            'status' => Agent::STATUS_ACTIVE,
        ]);
    }

    public function test_done_redirects_back_to_appropriate_step_when_not_complete(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('onboarding.done'))
            ->assertRedirect(route('onboarding.intro'));
    }
}
