<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Events\LeadMessage;
use App\Events\LeadSaved;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.test-key');
        config()->set('services.voiceflow.environment', 'main');
        config()->set('services.voiceflow.project_id', 'proj-123');
        config()->set('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.com');
        config()->set('services.voiceflow.webhook_secret', 'shh-secret');
    }

    /**
     * Fake the V4 two-step flow: start-session returns a sessionKey, interact
     * returns a { traces: [...] } envelope, state returns variables.
     *
     * @param  array<int, array<string, mixed>>  $traces
     * @param  array<string, mixed>  $variables
     */
    private function fakeV4(array $traces, array $variables = []): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['sessionKey' => 'sess-abc']),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => $traces]),
            // State lookup uses the documented endpoint per
            // docs/voiceflow/conversations/get-conversation-state.md:
            //   GET /state/user/{userID}  (with projectID header)
            // Earlier code had a fabricated /v4/.../state path — see the
            // VoiceflowService audit note.
            'general-runtime.voiceflow.com/state/user/*' => Http::response(['variables' => $variables]),
        ]);
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_service_parses_text_and_choice_traces(): void
    {
        $service = new VoiceflowService();

        $parsed = $service->parseTraces([
            ['type' => 'text', 'payload' => ['message' => 'Hello!']],
            ['type' => 'choice', 'payload' => ['buttons' => [
                ['name' => 'Yes', 'request' => ['type' => 'text', 'payload' => 'Yes']],
            ]]],
            ['type' => 'end', 'payload' => []],
        ]);

        $this->assertSame(['Hello!'], $parsed['messages']);
        $this->assertSame('Yes', $parsed['buttons'][0]['name']);
        $this->assertTrue($parsed['ended']);
    }

    public function test_service_extracts_only_configured_lead_fields(): void
    {
        $service = new VoiceflowService();

        $fields = $service->extractLeadFields([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'phone' => '',
            'unrelated' => 'ignore me',
        ]);

        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $fields);
    }

    public function test_launch_proxies_to_voiceflow_and_returns_messages(): void
    {
        $this->fakeV4([
            ['type' => 'text', 'payload' => ['message' => 'Hi, what is your name?']],
        ]);

        $this->actingAs($this->user())
            ->postJson(route('agent.launch'), [])
            ->assertOk()
            ->assertJsonStructure(['user_id', 'messages', 'buttons', 'ended', 'captured'])
            ->assertJsonPath('messages.0', 'Hi, what is your name?');
    }

    public function test_interact_captures_lead_from_variables_and_broadcasts(): void
    {
        Event::fake([LeadSaved::class, LeadMessage::class]);

        $this->fakeV4(
            traces: [['type' => 'text', 'payload' => ['message' => 'Thanks Ada!']]],
            variables: ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        );

        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('agent.interact'), [
                'user_id' => 'web-123',
                'message' => 'My name is Ada',
            ])
            ->assertOk()
            ->assertJsonPath('captured.name', 'Ada Lovelace');

        $this->assertDatabaseHas('leads', [
            'team_id' => $user->currentTeam->id,
            'email' => 'ada@example.com',
            'source' => 'voiceflow',
            'status' => LeadStatus::Engaging->value,
        ]);

        Event::assertDispatched(LeadSaved::class);
        Event::assertDispatched(LeadMessage::class);
    }

    public function test_launch_surfaces_upstream_auth_error(): void
    {
        // Start-session rejects the API key.
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->actingAs($this->user())
            ->postJson(route('agent.launch'), [])
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 401)
            ->assertJsonStructure(['error', 'upstream_status']);
    }

    public function test_health_ok_when_session_and_launch_succeed(): void
    {
        $this->fakeV4([['type' => 'text', 'payload' => ['message' => 'hi']]]);

        $health = (new VoiceflowService())->health();

        $this->assertTrue($health['ok']);
        $this->assertSame('main', $health['environment']);
    }

    public function test_health_reports_session_auth_failure(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['message' => 'nope'], 403),
        ]);

        $health = (new VoiceflowService())->health();

        $this->assertFalse($health['ok']);
        $this->assertSame('start_session', $health['step']);
        $this->assertStringContainsString('Key rejected', $health['reason']);
    }

    public function test_health_reports_agent_launch_failure(): void
    {
        // Session starts fine, but the agent 500s on launch.
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['sessionKey' => 'sess-abc']),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['message' => 'Internal server error'], 500),
        ]);

        $health = (new VoiceflowService())->health();

        $this->assertFalse($health['ok']);
        $this->assertSame('interact', $health['step']);
        $this->assertSame(500, $health['upstream_status']);
    }

    public function test_endpoints_return_503_when_unconfigured(): void
    {
        config()->set('services.voiceflow.api_key', null);

        $this->actingAs($this->user())
            ->postJson(route('agent.launch'), [])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_bad_secret(): void
    {
        $team = $this->user()->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        $this->postJson(route('voiceflow.webhook', $agent), [
            'name' => 'Grace',
        ], ['X-Webhook-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_webhook_creates_qualified_lead_and_broadcasts(): void
    {
        Event::fake([LeadSaved::class]);

        $team = $this->user()->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        $this->postJson(route('voiceflow.webhook', $agent), [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'score' => 90,
            'qualified' => true,
            'voiceflow_user_id' => 'web-xyz',
        ], ['X-Webhook-Secret' => $agent->webhook_secret])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Qualified + a team member present → auto-delegated (round-robin),
        // which advances the status to "assigned". Lead is stamped with the
        // per-agent agent_id from the route, not the team's current_agent_id.
        $this->assertDatabaseHas('leads', [
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'email' => 'grace@example.com',
            'status' => LeadStatus::Assigned->value,
            'source' => 'voiceflow',
            'assigned_to' => $team->owner->id,
        ]);
        $this->assertDatabaseHas('lead_assignments', [
            'team_id' => $team->id,
            'strategy' => 'round_robin',
        ]);

        Event::assertDispatched(LeadSaved::class);
    }

    public function test_webhook_requires_name_or_email(): void
    {
        $team = $this->user()->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        $this->postJson(route('voiceflow.webhook', $agent), [
            'phone' => '555-1234',
        ], ['X-Webhook-Secret' => $agent->webhook_secret])
            ->assertStatus(422);
    }

    public function test_webhook_rejects_disabled_agent(): void
    {
        $team = $this->user()->currentTeam;
        $agent = Agent::factory()->disabled()->for($team)->create();

        $this->postJson(route('voiceflow.webhook', $agent), [
            'name' => 'Grace',
        ], ['X-Webhook-Secret' => $agent->webhook_secret])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_another_agents_secret(): void
    {
        // Cross-agent isolation: agent A's URL with agent B's secret must fail.
        $team = $this->user()->currentTeam;
        $a = Agent::factory()->for($team)->create();
        $b = Agent::factory()->for($team)->create();

        $this->postJson(route('voiceflow.webhook', $a), [
            'name' => 'Grace',
            'email' => 'grace@example.com',
        ], ['X-Webhook-Secret' => $b->webhook_secret])
            ->assertStatus(401);
    }
}
