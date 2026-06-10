<?php

namespace App\Runtime\Contracts;

use App\Models\Agent;
use Generator;

/**
 * The agent runtime contract — what a "conversational engine" must do to
 * power a Flowstack agent.
 *
 * Two implementations live behind this:
 *
 *   1. VoiceflowRuntime (adapter) — wraps the existing VoiceflowService so
 *      Voiceflow-backed agents can continue to work unchanged while the
 *      native runtime is rolled out behind a per-agent feature flag.
 *
 *   2. NativeRuntime — our own implementation (AgentRuntime + Flow + LLM +
 *      Tools + KB). Built in Phases 2-7. Switched on per-agent via
 *      agents.runtime_mode='native'.
 *
 * Method signatures mirror what VoiceflowService already exposes so that
 * controllers + the embed flow can be swapped to depend on this contract
 * instead of the concrete VoiceflowService class. Once that's done, the
 * "which engine" decision moves entirely into a service provider binding
 * based on the agent's runtime_mode column.
 *
 * @api
 */
interface Runtime
{
    /**
     * Open or resume a session for a visitor. Returns the welcome traces
     * (text the agent says first) and any variables it sets immediately.
     *
     * @return list<array<string, mixed>> Traces: [{type, payload, ...}, ...]
     */
    public function launch(Agent $agent, string $visitorId): array;

    /**
     * Send a user message and return the agent's response traces.
     *
     * @return list<array<string, mixed>>
     */
    public function sendText(Agent $agent, string $visitorId, string $text): array;

    /**
     * Send a user message and stream the agent's response token-by-token.
     * Yields SSE-ready events of the form {event: 'token'|'tool'|'done', data: ...}.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function streamText(Agent $agent, string $visitorId, string $text): Generator;

    /**
     * End the current session (visitor closed the chat / explicit hand-off /
     * timeout cleanup). Idempotent.
     */
    public function endSession(Agent $agent, string $visitorId): void;

    /**
     * Quick "is this configured + can it answer?" check. Used by the
     * health-card on Agents/Show and by the public widget endpoints.
     *
     * @return array{ok: bool, configured: bool, reason?: string}
     */
    public function health(Agent $agent): array;
}
