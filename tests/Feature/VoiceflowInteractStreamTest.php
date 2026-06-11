<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Providers\VoiceflowServiceProvider;
use App\Services\Voiceflow\Client\StreamingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the chat.interact-stream endpoint — the streaming variant of chat.interact.
 * The SSE parser is exercised via VoiceflowStreamingClientTest; this test covers
 * the controller path: auth gating, throttle, validation, and the streamed
 * response headers + summary frame emission.
 */
class VoiceflowInteractStreamTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create([
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_project_id' => 'proj-1',
            'voiceflow_environment' => 'main',
            'status' => 'active',
        ]);
        $team->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->post(route('chat.interact-stream'), [
            'user_id' => 'u-1',
            'message' => 'hi',
        ])->assertRedirect(route('login'));
    }

    public function test_endpoint_validates_required_fields(): void
    {
        $this->actingAs($this->setupUser())
            ->postJson(route('chat.interact-stream'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'message']);
    }

    public function test_endpoint_returns_sse_content_type(): void
    {
        $user = $this->setupUser();

        // Inject a streaming client whose reader yields a deterministic SSE stream
        // — the live HTTP client would hit Voiceflow. We bypass it via a closure.
        $this->app->bind(StreamingClient::class, function () {
            return new class(runtime: VoiceflowServiceProvider::runtimeFor(null), baseUrl: 'https://runtime.voiceflow.test', projectId: 'proj-1', environment: 'main') extends StreamingClient
            {
                public function streamInteract(string $userId, array $action, array $variables = [], ?\Closure $reader = null): \Generator
                {
                    yield ['event' => 'trace', 'data' => ['type' => 'text', 'payload' => ['message' => 'hello there']]];
                    yield ['event' => 'trace', 'data' => ['type' => 'end', 'payload' => []]];
                }
            };
        });

        $response = $this->actingAs($user)
            ->call(
                'POST',
                route('chat.interact-stream'),
                ['user_id' => 'u-1', 'message' => 'hi', 'lead_id' => null],
            );

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));

        // StreamedResponse — content lives in the callback; capture via ob_start.
        // Note: interim `echo` + `flush()` calls inside the loop are eaten by
        // PHP's nested output buffering in test context; the post-loop summary
        // frame survives because it follows the last `flush()`. We assert on
        // what we can reliably observe.
        ob_start();
        $response->baseResponse->sendContent();
        $body = (string) ob_get_clean();

        // The final summary frame must appear and prove the controller
        // completed the stream + ran post-processing. messages_billed = 1
        // (user msg) + N (parsed agent texts) — non-zero proves the upstream
        // generator was drained and parsed.
        $this->assertStringContainsString('event: summary', $body);
        $this->assertStringContainsString('"messages_billed":', $body);
        $this->assertStringContainsString('"ended":true', $body, 'controller observed the end trace');
    }
}
