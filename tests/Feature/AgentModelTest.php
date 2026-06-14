<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_agent_auto_generates_slug(): void
    {
        $team = Team::factory()->create();

        $agent = Agent::create([
            'team_id' => $team->id,
            'name' => 'Sales bot',
        ]);

        $this->assertNotEmpty($agent->slug);
        $this->assertStringStartsWith('team-'.$team->id.'-', $agent->slug);
    }

    public function test_is_configured_is_always_true_on_the_native_runtime(): void
    {
        // No per-agent credentials exist — the engine's keys are platform
        // level (ANTHROPIC_API_KEY / OPENAI_API_KEY) and their presence is
        // reported by AgentRuntime::health, not by the row.
        $this->assertTrue(Agent::factory()->make()->isConfigured());
        $this->assertTrue(Agent::factory()->draft()->make()->isConfigured());
    }

    public function test_team_can_switch_agents_but_not_to_a_foreign_agent(): void
    {
        $team = Team::factory()->create();
        $mine = Agent::factory()->for($team)->create();
        $foreign = Agent::factory()->create(); // different team

        $this->assertTrue($team->switchAgent($mine));
        $this->assertSame($mine->id, $team->fresh()->current_agent_id);

        $this->assertFalse($team->switchAgent($foreign));
        $this->assertSame($mine->id, $team->fresh()->current_agent_id);
    }

    public function test_route_key_is_slug(): void
    {
        // Public embed URLs contain the slug, so route binding must
        // resolve {agent} by slug — not id.
        $this->assertSame('slug', (new Agent)->getRouteKeyName());
    }

    public function test_seeded_data_is_team_scoped_by_agent_id_after_backfill(): void
    {
        $newTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

        $this->assertNull($newTeam->fresh()->current_agent_id, 'New teams start without an agent');

        $agent = Agent::factory()->for($newTeam)->create();
        $lead = Lead::factory()->create(['team_id' => $newTeam->id, 'agent_id' => $agent->id]);

        $this->assertSame($agent->id, $lead->fresh()->agent_id);
        $this->assertSame($agent->id, $lead->agent->id);
    }
}
