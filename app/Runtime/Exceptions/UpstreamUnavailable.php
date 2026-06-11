<?php

namespace App\Runtime\Exceptions;

/**
 * Thrown when an upstream provider (Anthropic / OpenAI / any backend
 * the runtime depends on) returns an error that the runtime cannot
 * recover from in this turn.
 *
 * EmbedController catches the base RuntimeException and returns 503 —
 * the visitor sees "temporarily unavailable" instead of a 500.
 *
 * @api
 */
class UpstreamUnavailable extends RuntimeException {}
