<?php

namespace App\Runtime\Exceptions;

/**
 * Thrown when the upstream LLM provider (Anthropic / OpenAI), the
 * Voiceflow runtime, or any other backend the runtime depends on
 * returns an error that the runtime cannot recover from in this turn.
 *
 * EmbedController catches the base RuntimeException and returns 503 —
 * the visitor sees "temporarily unavailable" instead of a 500.
 *
 * @api
 */
class UpstreamUnavailable extends RuntimeException {}
