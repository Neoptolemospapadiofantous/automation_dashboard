<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the post-audit fix: /agent's `configured` prop reflects the
 * CURRENT user's agent, not the deployment-wide .env fallback.
 *
 * Before this fix, a SaaS user with their own perfectly configured agent
 * would see "Voiceflow not configured" if the Laravel deployment had no
 * global VOICEFLOW_API_KEY.
 */
class AgentIndexConfiguredFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_is_true_when_current_agent_has_credentials(): void
    {
        // Deployment-wide .env is intentionally empty — we're testing the
        // per-tenant path.
        config()->set('services.voiceflow.api_key', null);
        config()->set('services.voiceflow.project_id', null);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat/Index')
                ->where('configured', true)
            );
    }

    public function test_configured_is_false_when_no_current_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // No current_agent_id, no .env fallback.
        config()->set('services.voiceflow.api_key', null);
        config()->set('services.voiceflow.project_id', null);

        $this->actingAs($user)->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('configured', false));
    }

    public function test_configured_is_false_when_current_agent_is_unconfigured(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->unconfigured()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('configured', false));
    }
}
