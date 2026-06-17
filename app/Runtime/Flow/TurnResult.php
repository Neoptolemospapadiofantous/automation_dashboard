<?php

namespace App\Runtime\Flow;

/**
 * Outcome of one executed turn.
 *
 * traces     — display traces
 *              ([{type:'text', payload:{message, citations}}]) so the embed
 *              chat UI renders them without changes
 * finalState — flow_state after transitions were applied
 * toolEvents — what ran, in order, for streaming + observability
 *
 * KB citations that grounded the answer ride in the text trace's payload
 * ({message, citations}) — same carrier as the message text — so they
 * flow through the runtime and into the recorder without a separate field.
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
