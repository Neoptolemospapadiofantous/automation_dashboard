<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\NotReady;
use Generator;

/**
 * The native runtime facade — Flowstack's own conversational engine.
 *
 * Slots behind the Runtime contract alongside the legacy Voiceflow path.
 * Which runtime serves a given agent is selected by
 * agents.runtime_mode ('voiceflow' | 'native'), resolved in the service
 * container — controllers and the embed flow ask for the Runtime
 * contract and never know which engine answered.
 *
 * THIS IS A PHASE-1 STUB. The actual flow execution + LLM dispatch +
 * tool calling + RAG lands across Phases 2-7. The methods here throw
 * NotReady until each piece is wired in. The intentional
 * sequencing lets us land the migrations + contracts + Agent model
 * column in production with zero behavioural risk — no agent has
 * runtime_mode='native' yet, so the dispatcher never picks this class.
 */
class AgentRuntime implements Runtime
{
    public function launch(Agent $agent, string $visitorId): array
    {
        throw new NotReady('NativeRuntime::launch — implemented in Phase 4 (Flow).');
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        throw new NotReady('NativeRuntime::sendText — implemented in Phase 4 (Flow).');
    }

    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        // Phase 1 stub: yield a single "not_ready" event so the SSE
        // pipeline downstream has something to forward without throwing.
        // Phase 7 replaces this with the real streaming loop (token
        // events from the LLM + tool events from the registry).
        yield [
            'event' => 'not_ready',
            'data' => [
                'reason' => 'NativeRuntime::streamText — implemented in Phase 7 (Streaming).',
            ],
        ];
    }

    public function endSession(Agent $agent, string $visitorId): void
    {
        throw new NotReady('NativeRuntime::endSession — implemented in Phase 6 (Session).');
    }

    public function health(Agent $agent): array
    {
        // Health is the one method that's safe to answer in Phase 1 — it
        // just checks the agent's runtime_mode and reports what the engine
        // would do, without actually doing it.
        if ($agent->getAttribute('runtime_mode') !== Agent::RUNTIME_NATIVE) {
            return [
                'ok' => false,
                'configured' => false,
                'reason' => "Agent is on runtime_mode='".(string) $agent->getAttribute('runtime_mode')."'; native runtime won't be invoked.",
            ];
        }

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
}
