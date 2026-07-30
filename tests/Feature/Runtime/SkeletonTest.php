<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Contracts\Tool;
use App\Runtime\Models\KbChunk;
use App\Runtime\Models\KbDocument;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runtime foundation: contracts, models, migrations, health reporting.
 * The conversational behavior itself is covered in AgentRuntimeTest.
 */
class SkeletonTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_contract_is_implemented_by_facade(): void
    {
        $this->assertInstanceOf(Runtime::class, app(AgentRuntime::class));
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
            'embedding' => array_fill(0, 4, 0.1),
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $this->assertSame($agent->id, $doc->fresh()->agent_id);
        $this->assertSame($doc->id, $chunk->fresh()->document_id);
        $this->assertSame([0.1, 0.1, 0.1, 0.1], $chunk->fresh()->embedding);
        $this->assertSame('Pricing FAQ', $chunk->document->title);
    }

    public function test_runtime_session_model_persists_with_history(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-test-1',
            'flow_state' => 'discovery',
            'variables' => ['name' => 'Alice', 'score' => 80],
            'history' => [['role' => 'user', 'content' => 'hi']],
            'last_activity_at' => now(),
        ]);

        $fresh = $session->fresh();
        $this->assertSame('discovery', $fresh->flow_state);
        $this->assertSame(80, $fresh->variables['score']);
        $this->assertSame('hi', $fresh->history[0]['content']);
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

    public function test_agent_defaults_to_native_runtime_mode(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $this->assertSame('native', $agent->fresh()->runtime_mode);
    }

    public function test_agent_constants_match_persisted_runtime_mode_values(): void
    {
        // 'native' is the only engine; the constant exists for call-site
        // readability.
        $this->assertSame('native', Agent::RUNTIME_NATIVE);
    }

    public function test_native_runtime_health_reports_misconfiguration_clearly(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $runtime = app(AgentRuntime::class);

        // No LLM key configured.
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

    public function test_health_checks_the_agents_own_tier_provider_not_always_anthropic(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['model_tier' => 'gemini'],
        ]);

        $runtime = app(AgentRuntime::class);

        // Anthropic funded, embeddings funded, but the agent runs on GEMINI
        // and there is no Gemini key: it must report DEAD, not falsely healthy.
        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.embeddings.openai_api_key' => 'sk-openai-test',
            'runtime.llm.google.api_key' => '',
        ]);
        $h = $runtime->health($agent->fresh());
        $this->assertFalse($h['ok'], 'A Gemini agent with no Gemini key must be unhealthy');
        $this->assertSame('gemini', $h['tier']);
        $this->assertSame('google', $h['provider']);
        $this->assertStringContainsString('GEMINI_API_KEY', $h['reason']);

        // Set the Gemini key → healthy, and it reports the gemini model.
        config(['runtime.llm.google.api_key' => 'gm-test']);
        $h = $runtime->health($agent->fresh());
        $this->assertTrue($h['ok']);
        $this->assertSame('gemini', $h['tier']);
    }
}
