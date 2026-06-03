<?php

namespace App\Lifecycle;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Mixin for any model that has a state machine.
 *
 * The model declares stateMachine() once, and gains transitionTo() +
 * canTransitionTo() helpers that delegate to it. Callers can either go
 * through the model ($lead->transitionTo(LeadStatus::Qualified)) or
 * construct the machine directly when they need explicit context.
 */
trait HasLifecycle
{
    abstract public function stateMachine(): StateMachine;

    public function transitionTo(BackedEnum|string $to, array $context = []): Model
    {
        return $this->stateMachine()->transitionTo($to, $context);
    }

    public function canTransitionTo(BackedEnum|string $to): bool
    {
        return $this->stateMachine()->canTransitionTo($to);
    }
}
