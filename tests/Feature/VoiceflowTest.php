<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Events\LeadMessage;
use App\Events\LeadSaved;
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
        config()->set('services.voiceflow.version_id', 'production');
        config()->set('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.com');
        config()->set('services.voiceflow.webhook_secret', 'shh-secret');
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
        Http::fake([
            'general-runtime.voiceflow.com/state/user/*/interact' => Http::response([
                ['type' => 'text', 'payload' => ['message' => 'Hi, what is your name?']],
            ]),
            'general-runtime.voiceflow.com/state/user/*/variables' => Http::response([]),
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

        Http::fake([
            'general-runtime.voiceflow.com/state/user/*/interact' => Http::response([
                ['type' => 'text', 'payload' => ['message' => 'Thanks Ada!']],
            ]),
            'general-runtime.voiceflow.com/state/user/*/variables' => Http::response([
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ]),
        ]);

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

        $this->postJson(route('voiceflow.webhook'), [
            'team_id' => $team->id,
            'name' => 'Grace',
        ], ['X-Webhook-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_webhook_creates_qualified_lead_and_broadcasts(): void
    {
        Event::fake([LeadSaved::class]);

        $team = $this->user()->currentTeam;

        $this->postJson(route('voiceflow.webhook'), [
            'team_id' => $team->id,
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'score' => 90,
            'qualified' => true,
            'voiceflow_user_id' => 'web-xyz',
        ], ['X-Webhook-Secret' => 'shh-secret'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('leads', [
            'team_id' => $team->id,
            'email' => 'grace@example.com',
            'status' => LeadStatus::Qualified->value,
            'source' => 'voiceflow',
        ]);

        Event::assertDispatched(LeadSaved::class);
    }

    public function test_webhook_requires_name_or_email(): void
    {
        $team = $this->user()->currentTeam;

        $this->postJson(route('voiceflow.webhook'), [
            'team_id' => $team->id,
            'phone' => '555-1234',
        ], ['X-Webhook-Secret' => 'shh-secret'])
            ->assertStatus(422);
    }
}
