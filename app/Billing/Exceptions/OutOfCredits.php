<?php

namespace App\Billing\Exceptions;

use App\Billing\Plan;
use RuntimeException;

/**
 * Thrown when a team is out of credits and tries to consume more. Mapped
 * to HTTP 402 (Payment Required) by the exception handler.
 *
 * Carries the plan so the chat UI can show "Upgrade to keep chatting"
 * for Free and "Top up credits" for Pro/Business.
 */
class OutOfCredits extends RuntimeException
{
    public function __construct(
        public readonly Plan $plan,
        string $message = 'Out of credits for this billing period.',
    ) {
        parent::__construct($message);
    }
}
