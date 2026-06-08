<?php

namespace App\Events\Domain;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generic "any state machine just moved" event.
 *
 * Fired by StateMachine after every successful transition, alongside the
 * transition's specific typed event (LeadQualified, AgentActivated, …).
 * Use the typed event for behaviour-driven listeners; use this one for
 * cross-cutting concerns like audit logging.
 */
class StateChanged
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Model $model,
        public readonly BackedEnum|string $from,
        public readonly BackedEnum|string $to,
        public readonly array $context = [],
    ) {}
}
