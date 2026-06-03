<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_agent_auto_generates_slug_and_webhook_secret(): void
    {
        $team = Team::factory()->create();

        $agent = Agent::create([
            'team_id' => $team->id,
            'name' => 'Sales bot',
            'voiceflow_api_key' => 'VF.DM.example',
            'voiceflow_project_id' => 'proj-1',
        ]);

        $this->assertNotEmpty($agent->slug);
        $this->assertStringStartsWith('team-'.$team->id.'-', $agent->slug);
        $this->assertNotEmpty($agent->webhook_secret);
        $this->assertGreaterThanOrEqual(40, strlen($agent->webhook_secret));
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $agent = Agent::factory()->create([
            'voiceflow_api_key' => 'VF.DM.secret-key-value',
            'voiceflow_workspace_api_key' => 'VF.WS.workspace-secret',
        ]);

        // Through the model: the cast decrypts.
        $this->assertSame('VF.DM.secret-key-value', $agent->fresh()->voiceflow_api_key);
        $this->assertSame('VF.WS.workspace-secret', $agent->fresh()->voiceflow_workspace_api_key);

        // Raw DB row: should NOT contain the cleartext key.
        $raw = DB::table('agents')->where('id', $agent->id)->first();
        $this->assertNotSame('VF.DM.secret-key-value', $raw->voiceflow_api_key);
        $this->assertStringNotContainsString('secret-key-value', $raw->voiceflow_api_key);
    }

    public function test_credentials_never_leak_via_array_serialization(): void
    {
        // The Agent's UI is exposed through Inertia props; the $hidden list
        // is the guard that prevents the api key from ending up in a JSON blob.
        $agent = Agent::factory()->create();

        $array = $agent->toArray();

        $this->assertArrayNotHasKey('voiceflow_api_key', $array);
        $this->assertArrayNotHasKey('voiceflow_workspace_api_key', $array);
        $this->assertArrayNotHasKey('webhook_secret', $array);
    }

    public function test_is_configured_requires_both_key_and_project(): void
    {
        $this->assertFalse(Agent::factory()->unconfigured()->make()->isConfigured());
        $this->assertFalse(Agent::factory()->make(['voiceflow_api_key' => 'k', 'voiceflow_project_id' => null])->isConfigured());
        $this->assertFalse(Agent::factory()->make(['voiceflow_api_key' => null, 'voiceflow_project_id' => 'p'])->isConfigured());
        $this->assertTrue(Agent::factory()->make(['voiceflow_api_key' => 'k', 'voiceflow_project_id' => 'p'])->isConfigured());
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
        // Per-agent webhook URL contains the slug, so route binding must
        // resolve {agent} by slug — not id.
        $this->assertSame('slug', (new Agent())->getRouteKeyName());
    }

    public function test_seeded_data_is_team_scoped_by_agent_id_after_backfill(): void
    {
        // The backfill migration ran at the top of every test (RefreshDatabase
        // → migrate). After it runs, every team that exists at migration time
        // gets a default agent. New teams created in tests do NOT get one;
        // they go through the onboarding wizard. Confirm both:
        $newTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

        $this->assertNull($newTeam->fresh()->current_agent_id, 'New teams start without an agent');

        // And confirm the per-row FK works.
        $agent = Agent::factory()->for($newTeam)->create();
        $lead = Lead::factory()->create(['team_id' => $newTeam->id, 'agent_id' => $agent->id]);

        $this->assertSame($agent->id, $lead->fresh()->agent_id);
        $this->assertSame($agent->id, $lead->agent->id);
    }
}
