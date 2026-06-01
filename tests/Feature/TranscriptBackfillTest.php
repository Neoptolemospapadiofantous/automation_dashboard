<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranscriptBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.test-key');
        config()->set('services.voiceflow.project_id', 'proj-123');
        config()->set('services.voiceflow.analytics_url', 'https://analytics-api.voiceflow.com');
        config()->set('scout.driver', null);
    }

    public function test_service_parses_transcript_logs_into_messages(): void
    {
        $messages = (new VoiceflowService)->transcriptMessages([
            'logs' => [
                ['type' => 'action', 'data' => ['type' => 'text', 'payload' => 'Hi there']],
                ['type' => 'trace', 'data' => ['type' => 'text', 'payload' => ['message' => 'Hello! Your name?']]],
                ['type' => 'trace', 'data' => ['type' => 'debug', 'payload' => ['message' => 'ignored']]],
                ['type' => 'end', 'data' => []],
            ],
        ]);

        $this->assertSame(
            [
                ['role' => 'user', 'text' => 'Hi there', 'trace_type' => 'text'],
                ['role' => 'agent', 'text' => 'Hello! Your name?', 'trace_type' => 'text'],
            ],
            $messages,
        );
    }

    public function test_backfill_imports_transcripts_into_local_store(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        Http::fake([
            'analytics-api.voiceflow.com/v1/transcript/project/*' => Http::response([
                'transcripts' => [
                    ['id' => 't-1', 'userID' => 'web-aaa', 'sessionID' => 's-1', 'endedAt' => '2026-06-01'],
                ],
            ]),
            'analytics-api.voiceflow.com/v1/transcript/t-1*' => Http::response([
                'transcript' => [
                    'id' => 't-1',
                    'logs' => [
                        ['type' => 'action', 'data' => ['type' => 'text', 'payload' => 'Hello']],
                        ['type' => 'trace', 'data' => ['type' => 'text', 'payload' => ['message' => 'Hi, how can I help?']]],
                    ],
                ],
            ]),
        ]);

        $this->artisan('voiceflow:backfill', ['--team' => $team->id])
            ->assertSuccessful();

        $conversation = Conversation::where('team_id', $team->id)->where('voiceflow_transcript_id', 't-1')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('ended', $conversation->status);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'user', 'text' => 'Hello']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'Hi, how can I help?']);
    }

    public function test_backfill_is_idempotent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        Http::fake([
            'analytics-api.voiceflow.com/v1/transcript/project/*' => Http::response([
                'transcripts' => [['id' => 't-1', 'userID' => 'web-aaa']],
            ]),
            'analytics-api.voiceflow.com/v1/transcript/t-1*' => Http::response([
                'transcript' => ['id' => 't-1', 'logs' => [
                    ['type' => 'trace', 'data' => ['type' => 'text', 'payload' => ['message' => 'Hello']]],
                ]],
            ]),
        ]);

        $this->artisan('voiceflow:backfill', ['--team' => $team->id])->assertSuccessful();
        $this->artisan('voiceflow:backfill', ['--team' => $team->id])->assertSuccessful();

        // Only one conversation, not duplicated on the second run.
        $this->assertSame(1, Conversation::where('voiceflow_transcript_id', 't-1')->count());
    }
}
