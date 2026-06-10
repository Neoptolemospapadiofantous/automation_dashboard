<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Contracts\Tool;
use App\Runtime\Exceptions\NotReady;
use App\Runtime\Models\KbChunk;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — runtime skeleton.
 *
 * Verifies the contracts, models, migrations, and stub facade are wired
 * correctly so subsequent phases can flesh them out without surprises.
 */
class SkeletonTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_contract_is_implemented_by_facade(): void
    {
        $this->assertInstanceOf(Runtime::class, new AgentRuntime);
    }

    public function test_tool_and_knowledge_store_contracts_exist(): void
    {
        $this->assertTrue(interface_exists(Tool::class));
        $this->assertTrue(interface_exists(KnowledgeStore::class));
    }

    public function test_kb_document_and_chunk_models_persist(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $doc = KbDocument::create([
            'agent_id' => $agent->id,
            'title' => 'Pricing FAQ',
            'source' => 'text',
            'raw_content' => 'We charge $99/month.',
            'metadata' => ['author' => 'ops'],
            'chunk_count' => 1,
        ]);

        $chunk = KbChunk::create([
            'document_id' => $doc->id,
            'agent_id' => $agent->id,
            'position' => 0,
            'content' => 'We charge $99/month.',
            'embedding' => array_fill(0, 4, 0.1), // shape only — Phase 5 wires real embeddings
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $this->assertSame($agent->id, $doc->fresh()->agent_id);
        $this->assertSame($doc->id, $chunk->fresh()->document_id);
        $this->assertSame([0.1, 0.1, 0.1, 0.1], $chunk->fresh()->embedding);
        $this->assertSame('Pricing FAQ', $chunk->document->title);
    }

    public function test_runtime_session_model_persists(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-test-1',
            'flow_state' => 'discovery',
            'variables' => ['name' => 'Alice', 'score' => 80],
            'last_activity_at' => now(),
        ]);

        $fresh = $session->fresh();
        $this->assertSame('discovery', $fresh->flow_state);
        $this->assertSame(80, $fresh->variables['score']);
    }

    public function test_runtime_session_unique_per_agent_visitor(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'dup-test',
            'flow_state' => 'greeting',
        ]);

        $this->expectException(QueryException::class);

        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'dup-test',
            'flow_state' => 'greeting',
        ]);
    }

    public function test_agent_defaults_to_voiceflow_runtime_mode(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $this->assertSame('voiceflow', $agent->fresh()->runtime_mode);
    }

    public function test_native_runtime_health_reports_misconfiguration_clearly(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $runtime = new AgentRuntime;

        // Agent still on voiceflow mode — health should explain why native won't run.
        $h = $runtime->health($agent->fresh());
        $this->assertFalse($h['ok']);
        $this->assertStringContainsString('voiceflow', $h['reason']);

        // Flip to native, no LLM key configured.
        $agent->forceFill(['runtime_mode' => 'native'])->save();
        config(['runtime.llm.anthropic.api_key' => '', 'runtime.embeddings.openai_api_key' => '']);
        $h = $runtime->health($agent->fresh());
        $this->assertFalse($h['ok']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $h['reason']);

        // LLM key set, embedding key missing.
        config(['runtime.llm.anthropic.api_key' => 'sk-anthropic-test', 'runtime.embeddings.openai_api_key' => '']);
        $h = $runtime->health($agent->fresh());
        $this->assertFalse($h['ok']);
        $this->assertStringContainsString('OPENAI_API_KEY', $h['reason']);

        // Both keys set, native is ready.
        config(['runtime.llm.anthropic.api_key' => 'sk-anthropic-test', 'runtime.embeddings.openai_api_key' => 'sk-openai-test']);
        $h = $runtime->health($agent->fresh());
        $this->assertTrue($h['ok']);
        $this->assertSame('native', $h['engine']);
    }

    public function test_stream_text_yields_a_not_ready_event_until_phase_7_ships(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $runtime = new AgentRuntime;

        $events = [];
        foreach ($runtime->streamText($agent, 'v1', 'hello') as $e) {
            $events[] = $e;
        }

        $this->assertCount(1, $events);
        $this->assertSame('not_ready', $events[0]['event']);
        $this->assertArrayHasKey('reason', $events[0]['data']);
        $this->assertStringContainsString('Phase 7', $events[0]['data']['reason']);
    }

    public function test_agent_constants_match_persisted_runtime_mode_values(): void
    {
        // 'voiceflow' is the implicit default (DB default + dispatcher
        // match-default), so only the native constant exists.
        $this->assertSame('native', Agent::RUNTIME_NATIVE);
    }

    public function test_stub_methods_throw_runtime_not_ready_until_their_phase_ships(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $runtime = new AgentRuntime;

        $this->assertThrowsNotReady(fn () => $runtime->launch($agent, 'v1'));
        $this->assertThrowsNotReady(fn () => $runtime->sendText($agent, 'v1', 'hi'));
        $this->assertThrowsNotReady(fn () => $runtime->endSession($agent, 'v1'));
    }

    /** @param  callable():mixed  $callable */
    private function assertThrowsNotReady(callable $callable): void
    {
        try {
            $callable();
            $this->fail('Expected NotReady but nothing was thrown.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(NotReady::class, $e);
        }
    }
}
