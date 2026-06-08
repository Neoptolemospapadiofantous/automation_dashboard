<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VoiceflowWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceflowSessionLifecycleWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'webhook_secret' => 'sek-1234567890',
            'status' => 'active',
        ]);

        return $agent;
    }

    public function test_rejects_missing_secret(): void
    {
        $agent = $this->makeAgent();

        $this->postJson(route('voiceflow.webhook.session', $agent), [
            'type' => 'runtime.session.start',
            'data' => ['userID' => 'u-1', 'sessionID' => 's-1'],
            'time' => 1717000000000,
        ])->assertStatus(401);
    }

    public function test_rejects_bad_secret(): void
    {
        $agent = $this->makeAgent();

        $this->withHeader('X-Webhook-Secret', 'wrong')
            ->postJson(route('voiceflow.webhook.session', $agent), [
                'type' => 'runtime.session.start',
                'data' => ['userID' => 'u-1', 'sessionID' => 's-1'],
            ])->assertStatus(401);
    }

    public function test_rejects_disabled_agent(): void
    {
        $agent = $this->makeAgent();
        $agent->forceFill(['status' => 'disabled'])->save();

        $this->withHeader('X-Webhook-Secret', 'sek-1234567890')
            ->postJson(route('voiceflow.webhook.session', $agent), [
                'type' => 'runtime.session.start',
                'data' => ['userID' => 'u-1', 'sessionID' => 's-1'],
            ])->assertStatus(503);
    }

    public function test_persists_event_and_marks_processed(): void
    {
        $agent = $this->makeAgent();

        $this->withHeader('X-Webhook-Secret', 'sek-1234567890')
            ->postJson(route('voiceflow.webhook.session', $agent), [
                'type' => 'runtime.session.start',
                'data' => ['userID' => 'u-1', 'sessionID' => 's-1'],
                'time' => 1717000000000,
            ])->assertOk();

        $this->assertDatabaseHas('voiceflow_webhook_events', [
            'agent_id' => $agent->id,
            'event_type' => 'runtime.session.start',
            'voiceflow_user_id' => 'u-1',
            'voiceflow_session_key' => 's-1',
        ]);

        $row = VoiceflowWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($row->processed_at);
        $this->assertTrue($row->signature_valid);
    }

    public function test_idempotent_on_duplicate_delivery(): void
    {
        $agent = $this->makeAgent();
        $payload = [
            'type' => 'runtime.session.end',
            'data' => ['userID' => 'u-1', 'sessionID' => 's-1'],
            'time' => 1717000000000,
        ];

        $this->withHeader('X-Webhook-Secret', 'sek-1234567890')
            ->postJson(route('voiceflow.webhook.session', $agent), $payload)->assertOk();

        $this->withHeader('X-Webhook-Secret', 'sek-1234567890')
            ->postJson(route('voiceflow.webhook.session', $agent), $payload)->assertOk();

        $this->assertSame(1, VoiceflowWebhookEvent::query()->where('agent_id', $agent->id)->count());
    }

    public function test_session_end_marks_conversation_ended_and_stamps_transcript(): void
    {
        $agent = $this->makeAgent();

        $convo = Conversation::factory()->for($agent->team)->create([
            'agent_id' => $agent->id,
            'voiceflow_user_id' => 'u-99',
            'status' => 'active',
            'ended_at' => null,
        ]);

        $this->withHeader('X-Webhook-Secret', 'sek-1234567890')
            ->postJson(route('voiceflow.webhook.session', $agent), [
                'type' => 'runtime.session.end',
                'data' => [
                    'userID' => 'u-99',
                    'sessionID' => 's-99',
                    'transcriptID' => 't-final',
                ],
                'time' => 1717000000000,
            ])->assertOk();

        $fresh = $convo->fresh();
        $this->assertNotNull($fresh->ended_at);
        $this->assertSame('ended', $fresh->status);
        $this->assertSame('t-final', $fresh->voiceflow_transcript_id);
    }
}
