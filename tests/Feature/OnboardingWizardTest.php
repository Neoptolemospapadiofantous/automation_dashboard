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

    public function test_intro_post_creates_a_draft_agent_and_moves_to_step_2(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('onboarding.start'), ['name' => 'My first agent'])
            ->assertRedirect(route('onboarding.connect'));

        $this->assertDatabaseHas('agents', [
            'team_id' => $user->currentTeam->id,
            'name' => 'My first agent',
            'status' => Agent::STATUS_DRAFT,
        ]);
    }

    public function test_save_credentials_activates_agent_on_green_health(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*' => Http::response(['sessionKey' => 's'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => []], 200),
        ]);
        config()->set('services.voiceflow.api_key', 'VF.DM.x');
        config()->set('services.voiceflow.project_id', 'p');

        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->draft()->for($user->currentTeam)->create();

        $this->actingAs($user)->post(route('onboarding.save'), [
            'voiceflow_api_key' => 'VF.DM.aaaabbbbccccddddeeeeffff.realKey',
            'voiceflow_project_id' => 'aaaabbbbccccddddeeeeffff',
        ])->assertRedirect(route('onboarding.done'));

        $agent = $user->currentTeam->fresh()->currentAgent;
        $this->assertNotNull($agent);
        $this->assertSame(Agent::STATUS_ACTIVE, $agent->status);
        $this->assertSame('aaaabbbbccccddddeeeeffff', $agent->voiceflow_project_id);
    }

    public function test_save_credentials_stays_on_step_2_when_health_fails(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*' => Http::response(['error' => 'nope'], 401),
        ]);
        config()->set('services.voiceflow.api_key', 'VF.DM.x');
        config()->set('services.voiceflow.project_id', 'p');

        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->draft()->for($user->currentTeam)->create();

        $this->actingAs($user)->post(route('onboarding.save'), [
            // Valid format so we pass client-side regex and actually exercise
            // the upstream-401 path (not the format-validation path).
            'voiceflow_api_key' => 'VF.DM.aaaabbbbccccddddeeeeffff.badKey',
            'voiceflow_project_id' => 'aaaabbbbccccddddeeeeffff',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('voiceflow_api_key');

        $agent = $user->currentTeam->fresh()->currentAgent ?? $user->currentTeam->agents()->first();
        $this->assertSame(Agent::STATUS_DRAFT, $agent->status);
    }

    public function test_done_redirects_back_to_appropriate_step_when_not_complete(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('onboarding.done'))
            ->assertRedirect(route('onboarding.intro'));
    }
}
