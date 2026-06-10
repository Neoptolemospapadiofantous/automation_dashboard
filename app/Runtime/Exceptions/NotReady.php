<?php

namespace App\Runtime\Exceptions;

/**
 * Thrown by stubbed runtime methods during the Phase 1-7 incremental
 * build. If this lands in production it means an agent was flipped to
 * runtime_mode='native' before the relevant phase shipped.
 *
 * Subclass of the umbrella RuntimeException so EmbedController + other
 * callers catching RuntimeException get a clean 503 instead of a 500.
 *
 * @api
 */
class NotReady extends RuntimeException {}
