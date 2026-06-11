<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_current_team_agents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mine = Agent::factory()->for($user->currentTeam)->create();
        $foreign = Agent::factory()->create(); // different team

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Index')
                ->has('agents', 1)
                ->where('agents.0.id', $mine->id)
            );
    }

    public function test_store_creates_agent_and_redirects_to_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('agents.store'), ['name' => 'Support bot'])
            ->assertRedirectContains('/agents/');

        $this->assertDatabaseHas('agents', [
            'team_id' => $user->currentTeam->id,
            'name' => 'Support bot',
            'status' => Agent::STATUS_ACTIVE,
        ]);
    }

    public function test_update_only_accepts_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);

        $this->actingAs($user)->put(route('agents.update', $agent), [
            'name' => 'Renamed',
            'status' => 'disabled', // not accepted — silently dropped
        ])->assertRedirect();

        $agent->refresh();
        $this->assertSame('Renamed', $agent->name);
        $this->assertSame('active', $agent->status, 'only name is writable via the endpoint');
    }

    public function test_destroy_removes_agent_and_falls_back_current(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = Agent::factory()->for($user->currentTeam)->create();
        $b = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $a->id])->save();

        $this->actingAs($user)->delete(route('agents.destroy', $a))->assertRedirect(route('agents.index'));

        $this->assertDatabaseMissing('agents', ['id' => $a->id]);
        $this->assertSame($b->id, $user->currentTeam->fresh()->current_agent_id);
    }

    public function test_switch_current_sets_team_current_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = Agent::factory()->for($user->currentTeam)->create();
        $b = Agent::factory()->for($user->currentTeam)->create();

        $this->actingAs($user)
            ->put(route('current-agent.update'), ['agent_id' => $b->id])
            ->assertRedirect();

        $this->assertSame($b->id, $user->currentTeam->fresh()->current_agent_id);
    }

    public function test_switch_current_rejects_foreign_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $foreign = Agent::factory()->create(); // different team

        $this->actingAs($user)
            ->put(route('current-agent.update'), ['agent_id' => $foreign->id])
            ->assertStatus(403);
    }

    public function test_settings_page_blocks_foreign_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $foreign = Agent::factory()->create();

        $this->actingAs($user)->get(route('agents.show', $foreign))->assertStatus(403);
    }

    public function test_settings_page_hides_webhook_url_and_credentials_for_every_agent(): void
    {
        // The settings page exposes no credentials — none exist on the
        // native runtime.
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $this->actingAs($user)->get(route('agents.show', $agent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Show')
                ->where('webhook_url', null)
                ->missing('agent.webhook_secret')
                ->missing('agent.voiceflow_project_id')
                ->missing('agent.voiceflow_environment')
                ->missing('agent.has_api_key')
            );
    }
}
