<?php

namespace App\Runtime\Flow;

/**
 * Outcome of one executed turn.
 *
 * traces     — Voiceflow-compatible display traces
 *              ([{type:'text', payload:{message}}]) so the embed chat UI
 *              renders them without changes
 * finalState — flow_state after transitions were applied
 * toolEvents — what ran, in order, for streaming + observability
 */
class TurnResult
{
    /**
     * @param  list<array<string, mixed>>  $traces
     * @param  list<array{name: string, ok: bool}>  $toolEvents
     */
    public function __construct(
        public readonly array $traces,
        public readonly string $finalState,
        public readonly array $toolEvents,
    ) {}
}
