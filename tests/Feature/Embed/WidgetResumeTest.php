<?php

namespace Tests\Feature\Embed;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Flow\FlowExecutor;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session resume + client-supplied visitor identity on POST /embed/{slug}/launch.
 *
 * Two strategies are used here on purpose:
 *  - New-visitor / invalid-id cases fake the Runtime so launch() never touches
 *    a real LLM (canned traces are enough for the HTTP-level assertions).
 *  - Resume / transcript-mapping cases use the REAL AgentRuntime against a real
 *    RuntimeSession row, so hasSession()/transcript() run against the database.
 *    The greeting never fires on a resume, so no LLM call happens.
 */
class WidgetResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_visitor_greets_and_mints_a_visitor_id(): void
    {
        $this->fakeRuntimeWithNoSession();
        $agent = $this->makeAgent();

        $response = $this->postJson("/embed/{$agent->slug}/launch")
            ->assertOk()
            ->assertJson([
                'resumed' => false,
                'transcript' => [],
            ]);

        $body = $response->json();

        $this->assertNotEmpty($body['traces'], 'a new visitor should be greeted');
        $this->assertSame([], $body['transcript']);
        $this->assertMatchesRegularExpression('/^embed-[A-Za-z0-9]{16,48}$/', $body['visitor_id']);
    }

    public function test_returning_visitor_resumes_with_transcript_in_order(): void
    {
        // REAL runtime — exercise hasSession()/transcript() against the row.
        $agent = $this->makeAgent();
        $visitorId = 'embed-'.str_repeat('a', 28);

        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => 'collecting',
            'variables' => [],
            'history' => [
                ['role' => 'user', 'content' => 'I need pricing'],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Sure, happy to help!']]],
            ],
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson("/embed/{$agent->slug}/launch", [
            'visitor_id' => $visitorId,
        ])->assertOk();

        $response->assertJson([
            'resumed' => true,
            'visitor_id' => $visitorId,
            'traces' => [],
            'transcript' => [
                ['role' => 'user', 'text' => 'I need pricing'],
                ['role' => 'agent', 'text' => 'Sure, happy to help!'],
            ],
        ]);

        // No new session was minted / reset — the existing row is reused.
        $this->assertSame(1, RuntimeSession::query()->where('visitor_id', $visitorId)->count());
    }

    public function test_transcript_excludes_synthetic_greeting_and_tool_results(): void
    {
        $agent = $this->makeAgent();
        $visitorId = 'embed-'.str_repeat('b', 28);

        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => 'collecting',
            'variables' => [],
            'history' => [
                // Synthetic greeting prompt — must be dropped.
                ['role' => 'user', 'content' => FlowExecutor::OPENING_MESSAGE],
                // Assistant content-blocks → flattened text.
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hi']]],
                // tool_use the assistant emitted (no text) — produces no line.
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'lookup', 'input' => []]]],
                // tool_result (role=user, array content) — must be dropped.
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'result']]],
                // A real visitor message survives.
                ['role' => 'user', 'content' => 'thanks'],
            ],
            'last_activity_at' => now(),
        ]);

        $this->postJson("/embed/{$agent->slug}/launch", ['visitor_id' => $visitorId])
            ->assertOk()
            ->assertJson([
                'resumed' => true,
                'transcript' => [
                    ['role' => 'agent', 'text' => 'hi'],
                    ['role' => 'user', 'text' => 'thanks'],
                ],
            ]);
    }

    public function test_invalid_client_visitor_id_is_ignored_and_a_fresh_id_is_minted(): void
    {
        $this->fakeRuntimeWithNoSession();
        $agent = $this->makeAgent();

        $response = $this->postJson("/embed/{$agent->slug}/launch", [
            'visitor_id' => '../../etc',
        ])->assertOk();

        $minted = $response->json('visitor_id');

        $this->assertNotSame('../../etc', $minted);
        $this->assertMatchesRegularExpression('/^embed-[A-Za-z0-9]{16,48}$/', $minted);
        $response->assertJson(['resumed' => false]);
    }

    public function test_ended_session_does_not_resume_and_greets_instead(): void
    {
        // REAL runtime: an ended session must NOT resume — launch must greet.
        // Stub only the greeting (executor) path so no LLM call happens, while
        // hasSession()/find() still run against the real row.
        $agent = $this->makeAgent();
        $visitorId = 'embed-'.str_repeat('c', 28);

        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => 'ended',
            'variables' => [],
            'history' => [
                ['role' => 'user', 'content' => 'old chat'],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'bye']]],
            ],
            'last_activity_at' => now(),
        ]);

        $this->fakeRuntimeWithNoSession();

        $this->postJson("/embed/{$agent->slug}/launch", ['visitor_id' => $visitorId])
            ->assertOk()
            ->assertJson([
                'resumed' => false,
                'visitor_id' => $visitorId, // the (valid-shape) client id is honored
                'transcript' => [],
            ])
            ->assertJsonPath('traces.0.payload.message', 'hi');
    }

    /**
     * Fake the Runtime contract so launch greets (hasSession false) with canned
     * traces — no LLM, no network.
     */
    private function fakeRuntimeWithNoSession(): void
    {
        $this->app->instance(Runtime::class, new class implements Runtime
        {
            public function launch(Agent $agent, string $visitorId): array
            {
                return [['type' => 'text', 'payload' => ['message' => 'hi']]];
            }

            public function hasSession(Agent $agent, string $visitorId): bool
            {
                return false;
            }

            public function transcript(Agent $agent, string $visitorId): array
            {
                return [];
            }

            public function sendText(Agent $agent, string $visitorId, string $text): array
            {
                return [['type' => 'text', 'payload' => ['message' => 'hi']]];
            }

            public function streamText(Agent $agent, string $visitorId, string $text): \Generator
            {
                yield ['event' => 'done', 'data' => []];
            }

            public function endSession(Agent $agent, string $visitorId): void {}

            public function health(Agent $agent): array
            {
                return ['ok' => true, 'configured' => true];
            }
        });
    }

    private function makeAgent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create([
            'status' => 'active',
            'allowed_domains' => [],
        ]);
    }
}
