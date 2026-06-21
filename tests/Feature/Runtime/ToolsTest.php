<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_builds_anthropic_specs_for_known_tools_only(): void
    {
        $registry = app(ToolRegistry::class);

        $specs = $registry->specs(['capture_lead', 'query_kb', 'nonexistent_tool']);

        $this->assertCount(2, $specs);
        $this->assertSame('capture_lead', $specs[0]['name']);
        $this->assertArrayHasKey('input_schema', $specs[0]);
        $this->assertSame('object', $specs[0]['input_schema']['type']);
    }

    public function test_dispatch_unknown_tool_returns_error_result(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $out = $registry->dispatch(new ToolCall('t1', 'ghost_tool', []), $context);

        $this->assertTrue($out['is_error']);
        $this->assertStringContainsString('ghost_tool', $out['content']);
    }

    public function test_capture_lead_creates_lead_with_status_new(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $out = $registry->dispatch(new ToolCall('t1', 'capture_lead', [
            'name' => 'Bob Buyer',
            'email' => 'bob@buyer.co',
            'company' => 'Buyer Co',
            'notes' => 'Wants the Operator plan',
            'score' => 85,
        ]), $context);

        $this->assertFalse($out['is_error']);
        $this->assertDatabaseHas('leads', [
            'team_id' => $context->agent->team_id,
            'agent_id' => $context->agent->id,
            'email' => 'bob@buyer.co',
            'name' => 'Bob Buyer',
            'score' => 85,
            'status' => 'new',
            'source' => 'chat',
        ]);
    }

    public function test_capture_lead_sums_fit_intent_urgency_into_score(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $out = $registry->dispatch(new ToolCall('t1', 'capture_lead', [
            'name' => 'Ada Scorer',
            'email' => 'ada@scorer.co',
            'fit' => 38,
            'intent' => 30,
            'urgency' => 20,
        ]), $context);

        $this->assertFalse($out['is_error']);

        $lead = Lead::where('email', 'ada@scorer.co')->firstOrFail();
        // Server-side sum, not model arithmetic: 38 + 30 + 20 = 88.
        $this->assertSame(88, $lead->score);
        $this->assertSame(['fit' => 38, 'intent' => 30, 'urgency' => 20], $lead->score_breakdown);
    }

    public function test_capture_lead_clamps_each_dimension_to_its_band(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $registry->dispatch(new ToolCall('t1', 'capture_lead', [
            'name' => 'Over Scorer',
            'email' => 'over@scorer.co',
            'fit' => 99,      // clamps to 40
            'intent' => 99,   // clamps to 35
            'urgency' => 99,  // clamps to 25
        ]), $context);

        $lead = Lead::where('email', 'over@scorer.co')->firstOrFail();
        $this->assertSame(100, $lead->score);
        $this->assertSame(['fit' => 40, 'intent' => 35, 'urgency' => 25], $lead->score_breakdown);
    }

    public function test_capture_lead_legacy_flat_score_stores_no_breakdown(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $registry->dispatch(new ToolCall('t1', 'capture_lead', [
            'name' => 'Legacy Lead',
            'email' => 'legacy@scorer.co',
            'score' => 72,
        ]), $context);

        $lead = Lead::where('email', 'legacy@scorer.co')->firstOrFail();
        $this->assertSame(72, $lead->score);
        $this->assertNull($lead->score_breakdown);
    }

    public function test_capture_lead_dedupes_on_email(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $registry->dispatch(new ToolCall('t1', 'capture_lead', ['name' => 'Bob', 'email' => 'bob@x.co']), $context);
        $registry->dispatch(new ToolCall('t2', 'capture_lead', ['name' => 'Robert', 'email' => 'bob@x.co', 'score' => 90]), $context);

        $this->assertSame(1, Lead::where('email', 'bob@x.co')->count());
        $this->assertSame('Robert', Lead::where('email', 'bob@x.co')->first()->name);
    }

    public function test_set_variable_merges_into_session(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context(['existing' => 'kept']);

        $out = $registry->dispatch(new ToolCall('t1', 'set_variable', ['name' => 'budget', 'value' => 'under $500']), $context);

        $this->assertFalse($out['is_error']);
        $vars = $context->session->fresh()->variables;
        $this->assertSame('kept', $vars['existing']);
        $this->assertSame('under $500', $vars['budget']);
    }

    public function test_set_variable_rejects_internal_names(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $out = $registry->dispatch(new ToolCall('t1', 'set_variable', ['name' => '_turns', 'value' => '999']), $context);

        $this->assertStringContainsString('Invalid', $out['content']);
        $this->assertArrayNotHasKey('_turns', (array) $context->session->fresh()->variables);
    }

    public function test_request_handoff_flags_session(): void
    {
        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $registry->dispatch(new ToolCall('t1', 'request_handoff', ['reason' => 'asked for legal review']), $context);

        $vars = $context->session->fresh()->variables;
        $this->assertTrue($vars['handoff_requested']);
        $this->assertSame('asked for legal review', $vars['handoff_reason']);
    }

    public function test_query_kb_formats_results_with_citations(): void
    {
        $this->mock(KnowledgeStore::class, function (MockInterface $mock): void {
            $mock->shouldReceive('search')->once()->andReturn([
                ['chunk' => 'Starter costs $99/mo.', 'chunk_id' => 1, 'document_id' => 1, 'document_title' => 'Pricing', 'score' => 0.91, 'metadata' => []],
            ]);
        });

        $registry = app(ToolRegistry::class);
        $context = $this->context();

        $out = $registry->dispatch(new ToolCall('t1', 'query_kb', ['question' => 'price?']), $context);

        $this->assertFalse($out['is_error']);
        $this->assertStringContainsString('(Pricing)', $out['content']);
        $this->assertStringContainsString('$99/mo', $out['content']);
    }

    public function test_tool_exception_becomes_error_result_not_crash(): void
    {
        $this->mock(KnowledgeStore::class, function (MockInterface $mock): void {
            $mock->shouldReceive('search')->andThrow(new \RuntimeException('embeddings down'));
        });

        $registry = app(ToolRegistry::class);
        $out = $registry->dispatch(new ToolCall('t1', 'query_kb', ['question' => 'x']), $this->context());

        $this->assertTrue($out['is_error']);
        $this->assertStringContainsString('embeddings down', $out['content']);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function context(array $variables = []): ConversationContext
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);
        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'v-'.uniqid(),
            'flow_state' => 'discovery',
            'variables' => $variables,
            'history' => [],
        ]);

        return new ConversationContext($agent, $session, 'test message');
    }
}
