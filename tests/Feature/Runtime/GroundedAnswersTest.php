<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Flow\FlowExecutor;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\LLM\CompletionResult;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Grounded answers + confidence-gated auto-escalate.
 *
 * Mocks the runtime's two external seams in the container — the
 * KnowledgeStore (to dictate the retrieval top_score precisely) and the
 * Anthropic LlmClient (to decide whether the model self-escalates) — so
 * the confidence gate in FlowExecutor can be exercised deterministically
 * without any HTTP or embedding maths.
 */
class GroundedAnswersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The router still asks for the anthropic key when resolving a tier;
        // the threshold default we pin explicitly so the maths is obvious.
        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.rag.answer_confidence' => 0.45,
        ]);
    }

    public function test_low_confidence_with_kb_auto_escalates_when_model_stays_silent(): void
    {
        Notification::fake();

        $owner = $this->ownerWithAgent($agent, autoEscalate: true);

        // KB has documents, but the best match is well below 0.45.
        $this->fakeKnowledge(hasDocuments: true, topScore: 0.20, title: 'Pricing FAQ');
        // The model just answers — it does NOT call request_handoff.
        $this->fakeLlm($this->textResult('Hmm, let me think about that.'));

        $this->seedSession($agent, 'v1', 'discovery');
        app(AgentRuntime::class)->sendText($agent, 'v1', 'do you support SAML SSO?');

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertTrue((bool) ($session->variables['handoff_requested'] ?? false));

        Notification::assertSentTo($owner, HandoffRequestedNotification::class);
    }

    public function test_high_confidence_answers_normally_with_citations_and_no_escalation(): void
    {
        Notification::fake();

        $this->ownerWithAgent($agent, autoEscalate: true);

        // Strong match → above the threshold → answer from KB, cite it.
        $this->fakeKnowledge(hasDocuments: true, topScore: 0.92, title: 'Pricing FAQ');
        $this->fakeLlm($this->textResult('The Starter plan is $99/month.'));

        $this->seedSession($agent, 'v1', 'discovery');
        $traces = app(AgentRuntime::class)->sendText($agent, 'v1', 'how much is the starter plan?');

        // No handoff fired.
        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertArrayNotHasKey('handoff_requested', (array) $session->variables);
        Notification::assertNothingSent();

        // Citations ride out on the trace payload AND the TurnResult-derived trace.
        $citations = $traces[0]['payload']['citations'];
        $this->assertNotEmpty($citations);
        $this->assertSame('Pricing FAQ', $citations[0]['document_title']);
        $this->assertSame(0.92, $citations[0]['score']);
    }

    public function test_toggle_off_never_escalates_even_on_a_low_score(): void
    {
        Notification::fake();

        $this->ownerWithAgent($agent, autoEscalate: false);

        $this->fakeKnowledge(hasDocuments: true, topScore: 0.10, title: 'Pricing FAQ');
        $this->fakeLlm($this->textResult('I am not certain about that.'));

        $this->seedSession($agent, 'v1', 'discovery');
        app(AgentRuntime::class)->sendText($agent, 'v1', 'obscure question');

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertArrayNotHasKey('handoff_requested', (array) $session->variables);
        Notification::assertNothingSent();
    }

    public function test_no_knowledge_base_never_escalates_on_a_weak_score(): void
    {
        Notification::fake();

        $this->ownerWithAgent($agent, autoEscalate: true);

        // Empty KB: search returns nothing (score 0.0) AND hasDocuments() is false.
        // The agent answers from instructions — a weak score must not trigger.
        $this->fakeKnowledge(hasDocuments: false, topScore: 0.0, title: null);
        $this->fakeLlm($this->textResult('Happy to help with that!'));

        $this->seedSession($agent, 'v1', 'discovery');
        app(AgentRuntime::class)->sendText($agent, 'v1', 'just chatting');

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertArrayNotHasKey('handoff_requested', (array) $session->variables);
        Notification::assertNothingSent();
    }

    public function test_model_self_escalation_does_not_double_fire_the_backstop(): void
    {
        Notification::fake();

        $owner = $this->ownerWithAgent($agent, autoEscalate: true);

        $this->fakeKnowledge(hasDocuments: true, topScore: 0.15, title: 'Pricing FAQ');
        // Low confidence AND the model escalates itself via request_handoff.
        $this->fakeLlm(
            $this->toolUseResult('request_handoff', ['reason' => 'beyond my knowledge']),
            $this->textResult('A teammate will reach out shortly.'),
        );

        $this->seedSession($agent, 'v1', 'discovery');
        app(AgentRuntime::class)->sendText($agent, 'v1', 'deeply technical question');

        $session = RuntimeSession::where('visitor_id', 'v1')->first();
        $this->assertTrue((bool) ($session->variables['handoff_requested'] ?? false));

        // Exactly one handoff notification — the deterministic backstop saw
        // the tool fire and stood down.
        Notification::assertSentToTimes($owner, HandoffRequestedNotification::class, 1);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Create an owner + native agent; binds $agent out by reference.
     */
    private function ownerWithAgent(?Agent &$agent, bool $autoEscalate): User
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
            'auto_escalate_low_confidence' => $autoEscalate,
        ]);

        return $owner;
    }

    private function seedSession(Agent $agent, string $visitorId, string $state): void
    {
        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => $state,
            'variables' => [],
            'history' => [],
        ]);
    }

    /**
     * Bind a fake KnowledgeStore that returns one chunk at the given score
     * (or nothing, for the empty-KB case).
     */
    private function fakeKnowledge(bool $hasDocuments, float $topScore, ?string $title): void
    {
        $results = $title === null ? [] : [[
            'chunk' => 'Some retrieved context.',
            'chunk_id' => 7,
            'document_id' => 42,
            'document_title' => $title,
            'score' => $topScore,
            'metadata' => ['source' => 'text'],
        ]];

        $this->app->instance(KnowledgeStore::class, new class($hasDocuments, $results) implements KnowledgeStore
        {
            /** @param list<array<string, mixed>> $results */
            public function __construct(private bool $has, private array $results) {}

            public function ingestDocument(int $agentId, string $title, string $content, array $metadata = []): int
            {
                return 1;
            }

            public function search(int $agentId, string $question, int $topK = 5): array
            {
                return $this->results;
            }

            public function hasDocuments(int $agentId): bool
            {
                return $this->has;
            }

            public function deleteDocument(int $agentId, int $documentId): void {}

            public function listDocuments(int $agentId): array
            {
                return [];
            }
        });
    }

    /**
     * Bind a fake Anthropic LlmClient that replays the given results in
     * order (the router resolves the anthropic provider for the default
     * tier, so binding AnthropicClient swaps the client the executor uses).
     */
    private function fakeLlm(CompletionResult ...$results): void
    {
        // Extend the concrete AnthropicClient so it satisfies LlmRouter's
        // constructor type-hint while replaying canned completions instead
        // of hitting the wire.
        $client = new class(array_values($results)) extends AnthropicClient
        {
            /** @param list<CompletionResult> $queue */
            public function __construct(private array $queue) {}

            public function complete(string $system, array $messages, array $tools = [], ?string $model = null, ?int $maxTokens = null): CompletionResult
            {
                return array_shift($this->queue) ?? new CompletionResult('', [], [['type' => 'text', 'text' => '']], 'end_turn', 0, 0);
            }
        };

        // AnthropicClient is constructor-injected into LlmRouter; replace the
        // concrete so clientFor('anthropic') hands back our fake.
        $this->app->instance(AnthropicClient::class, $client);
    }

    private function textResult(string $text): CompletionResult
    {
        return new CompletionResult(
            text: $text,
            toolCalls: [],
            contentBlocks: [['type' => 'text', 'text' => $text]],
            stopReason: 'end_turn',
            inputTokens: 10,
            outputTokens: 10,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function toolUseResult(string $tool, array $input): CompletionResult
    {
        $id = 'toolu_'.uniqid();

        return new CompletionResult(
            text: '',
            toolCalls: [new ToolCall($id, $tool, $input)],
            contentBlocks: [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
            stopReason: 'tool_use',
            inputTokens: 20,
            outputTokens: 15,
        );
    }
}
