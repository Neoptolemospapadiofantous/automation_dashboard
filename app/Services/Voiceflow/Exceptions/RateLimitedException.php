<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * 429 from Voiceflow. Currently only documented for batch transcript evaluations,
 * but treat as a general signal — Voiceflow may add it elsewhere without notice.
 */
final class RateLimitedException extends VoiceflowException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?int $httpStatus = 429,
        ?string $endpoint = null,
        array $context = [],
    ) {
        parent::__construct($message, $httpStatus, $endpoint, $context);
    }
}
