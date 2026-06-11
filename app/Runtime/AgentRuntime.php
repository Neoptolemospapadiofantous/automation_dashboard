<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Flow\FlowExecutor;
use App\Runtime\Flow\LeadCaptureFlow;
use App\Runtime\Flow\TurnResult;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Session\SessionManager;
use Generator;

/**
 * Flowstack's native conversational engine.
 *
 * Composition: SessionManager owns per-visitor state, FlowExecutor runs
 * the LLM/tool loop against the agent's Flow (LeadCaptureFlow for every
 * agent today; per-agent flow selection is a column away when templates
 * land). Credits are charged by the CONTROLLERS around these calls —
 * this class knows nothing about billing.
 *
 * Bound to the Runtime contract in AppServiceProvider — the only engine.
 */
class AgentRuntime implements Runtime
{
    public function __construct(
        protected FlowExecutor $executor,
        protected SessionManager $sessions,
        protected LeadCaptureFlow $flow,
    ) {}

    /**
     * Reset-and-greet (the embed iframe calls launch on every open; the
     * greeting replays from a fresh session).
     */
    public function launch(Agent $agent, string $visitorId): array
    {
        $session = $this->sessions->reset($agent, $visitorId, $this->flow->initial());

        $result = $this->executor->execute(
            new ConversationContext($agent, $session, FlowExecutor::OPENING_MESSAGE),
            $this->flow,
        );

        return $result->traces;
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        return $this->turn($agent, $visitorId, $text)->traces;
    }

    /**
     * Stage-level streaming: the turn runs to completion, then events are
     * yielded in order (tool events → message → done). Token-level SSE
     * pass-through from Anthropic is a planned refinement; the embed UI
     * consumes whole messages today so this shape is already sufficient.
     */
    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        $result = $this->turn($agent, $visitorId, $text);

        foreach ($result->toolEvents as $event) {
            yield ['event' => 'tool', 'data' => $event];
        }

        foreach ($result->traces as $trace) {
            yield ['event' => 'message', 'data' => $trace['payload'] ?? []];
        }

        yield ['event' => 'done', 'data' => ['state' => $result->finalState]];
    }

    public function endSession(Agent $agent, string $visitorId): void
    {
        $this->sessions->end($agent, $visitorId);
    }

    public function health(Agent $agent): array
    {
        $hasLlmKey = (string) config('runtime.llm.anthropic.api_key') !== '';
        $hasEmbeddingKey = (string) config('runtime.embeddings.openai_api_key') !== '';

        if (! $hasLlmKey) {
            return ['ok' => false, 'configured' => false, 'reason' => 'ANTHROPIC_API_KEY not set.'];
        }
        if (! $hasEmbeddingKey) {
            return ['ok' => false, 'configured' => false, 'reason' => 'OPENAI_API_KEY (for embeddings) not set.'];
        }

        return [
            'ok' => true,
            'configured' => true,
            'engine' => 'native',
            'llm_model' => (string) config('runtime.llm.anthropic.model_default'),
            'embedding_model' => (string) config('runtime.embeddings.model'),
        ];
    }

    protected function turn(Agent $agent, string $visitorId, string $text): TurnResult
    {
        $session = $this->sessions->findOrCreate($agent, $visitorId, $this->flow->initial());

        // Safety cap: a session can't run forever (cost protection).
        $maxTurns = max(1, (int) config('runtime.safety.max_turns_per_session'));
        if ($this->sessions->userTurnCount($session) >= $maxTurns) {
            return new TurnResult(
                traces: [['type' => 'text', 'payload' => [
                    'message' => 'This conversation has reached its limit — a teammate will pick it up from here. Thanks for your patience!',
                ]]],
                finalState: $session->flow_state,
                toolEvents: [],
            );
        }

        return $this->executor->execute(
            new ConversationContext($agent, $session, $text),
            $this->flow,
        );
    }
}
