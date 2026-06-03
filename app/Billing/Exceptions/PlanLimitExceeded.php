<?php

namespace App\Billing\Exceptions;

use App\Billing\Plan;
use RuntimeException;

/**
 * Thrown when a team tries to do something its current plan doesn't allow
 * (e.g. create a 6th agent on Pro). Carries the limit metadata so the UI
 * can render an upgrade CTA without having to re-derive it.
 */
class PlanLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $limit,
        public readonly Plan $plan,
    ) {
        parent::__construct(
            "Your {$plan->label()} plan allows up to {$limit} {$resource}. Upgrade to add more."
        );
    }
}
