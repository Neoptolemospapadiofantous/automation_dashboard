<?php

namespace App\Runtime\Contracts;

use App\Models\Agent;
use Generator;

/**
 * The agent runtime contract — what a "conversational engine" must do to
 * power a Flowstack agent.
 *
 * AgentRuntime (the native engine) is the only implementation today.
 * The contract is kept as the seam for any future engine — bind a
 * dispatcher again the day a second engine exists; controllers + the
 * embed flow only ever see this interface.
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
     * Whether a returning visitor has a live (non-ended, non-empty) session
     * to resume — so the embed can restore the transcript instead of
     * resetting and re-greeting on every page load.
     */
    public function hasSession(Agent $agent, string $visitorId): bool;

    /**
     * The prior conversation as display messages for a resuming visitor.
     *
     * @return list<array{role: string, text: string}>
     */
    public function transcript(Agent $agent, string $visitorId): array;

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
