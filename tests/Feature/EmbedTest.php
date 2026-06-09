<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_js_renders_for_active_agent(): void
    {
        $agent = $this->makeAgent('active');

        $response = $this->get("/widget/{$agent->slug}.js")->assertOk();
        $this->assertSame('application/javascript; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("/embed/{$agent->slug}", $response->getContent());
        $this->assertStringContainsString('fs-embed-btn', $response->getContent());
    }

    public function test_widget_js_404s_for_unknown_or_inactive_agent(): void
    {
        $this->get('/widget/team-fake-9999.js')->assertNotFound();

        $draft = $this->makeAgent('draft');
        $this->get("/widget/{$draft->slug}.js")->assertNotFound();
    }

    public function test_embed_chat_page_renders_for_active_agent(): void
    {
        $agent = $this->makeAgent('active');

        $this->get("/embed/{$agent->slug}")
            ->assertOk()
            ->assertSee($agent->name)
            ->assertSee('Type a message');
    }

    public function test_embed_chat_page_allows_iframe_embedding(): void
    {
        $agent = $this->makeAgent('active');

        $response = $this->get("/embed/{$agent->slug}");
        $this->assertStringContainsString(
            'frame-ancestors *',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_embed_launch_creates_visitor_and_returns_traces(): void
    {
        $agent = $this->makeAgent('active');

        // Fake Voiceflow's HTTP responses at the network layer.
        Http::fake([
            'general-runtime.voiceflow.com/*/session*' => Http::response(['sessionKey' => 'sk_test'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response([
                ['type' => 'text', 'payload' => ['message' => 'Welcome!']],
            ], 200),
        ]);

        $response = $this->postJson("/embed/{$agent->slug}/launch");

        $response->assertOk()
            ->assertJsonStructure(['visitor_id', 'agent_name', 'traces'])
            ->assertJsonPath('agent_name', $agent->name);
    }

    public function test_embed_launch_404s_for_inactive_agent(): void
    {
        $draft = $this->makeAgent('draft');

        $this->postJson("/embed/{$draft->slug}/launch")->assertNotFound();
    }

    public function test_embed_interact_validates_required_fields(): void
    {
        $agent = $this->makeAgent('active');

        $this->postJson("/embed/{$agent->slug}/interact", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visitor_id', 'message']);
    }

    public function test_embed_interact_402s_when_team_out_of_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 0])->save();

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'Hello',
        ])->assertStatus(402);
    }

    public function test_embed_interact_succeeds_and_debits_credits(): void
    {
        $agent = $this->makeAgent('active');
        $agent->team->forceFill(['credit_balance' => 100])->save();

        Http::fake([
            'general-runtime.voiceflow.com/*/session*' => Http::response(['sessionKey' => 'sk_test'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response([
                ['type' => 'text', 'payload' => ['message' => 'Got it!']],
            ], 200),
        ]);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-test',
            'message' => 'Hello',
        ])->assertOk()
            ->assertJsonStructure(['traces']);

        $this->assertSame(99, $agent->team->fresh()->credit_balance);
    }

    private function makeAgent(string $status): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create([
            'status' => $status,
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_project_id' => 'proj-test',
            'voiceflow_environment' => 'main',
        ]);
    }
}
