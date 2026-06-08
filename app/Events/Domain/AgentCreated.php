<?php

namespace App\Events\Domain;

use App\Models\Agent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Distinct from StateChanged: fired exactly once when an Agent row is
 * persisted, even though that's not a state transition. Lets OnboardingState
 * react to "team now has an agent" without polling.
 */
class AgentCreated
{
    use Dispatchable;

    public function __construct(public readonly Agent $agent) {}
}
