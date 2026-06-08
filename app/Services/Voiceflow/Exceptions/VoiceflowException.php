<?php

namespace App\Services\Voiceflow\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for every Voiceflow integration failure. Subclasses map to HTTP status
 * families so callers can `catch (AuthException)` / `catch (UpstreamException)`
 * etc. without inspecting status codes by hand.
 */
abstract class VoiceflowException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $endpoint = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Structured payload for logs / observability. Never include raw API keys.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'voiceflow_status' => $this->httpStatus,
            'voiceflow_endpoint' => $this->endpoint,
            'voiceflow_class' => static::class,
        ] + $this->context;
    }
}
