<?php

namespace App\Lifecycle;

use App\Events\Domain\AgentActivated;
use App\Events\Domain\AgentDisabled;
use App\Models\Agent;

/**
 * Agent status flow:
 *
 *   draft ─► active ⇄ disabled
 *
 * Rules:
 * - draft → active is gated: the agent must have credentials AND its last
 *   health check must have passed. This is the one place that rule lives.
 * - active ⇄ disabled is freely reversible (user pauses then resumes).
 * - No path back to draft: once an agent has been configured, "disable" is
 *   the way to take it out of rotation without losing the keys.
 */
class AgentStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            new Transition(
                from: Agent::STATUS_DRAFT,
                to: Agent::STATUS_ACTIVE,
                guard: fn (Agent $a) => $a->isConfigured() && $a->last_health_ok,
                event: AgentActivated::class,
            ),
            new Transition(
                from: Agent::STATUS_ACTIVE,
                to: Agent::STATUS_DISABLED,
                event: AgentDisabled::class,
            ),
            new Transition(
                from: Agent::STATUS_DISABLED,
                to: Agent::STATUS_ACTIVE,
                guard: fn (Agent $a) => $a->isConfigured(),
                event: AgentActivated::class,
            ),
        ];
    }
}
