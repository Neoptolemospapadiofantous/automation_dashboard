<?php

namespace App\Runtime\Exceptions;

/**
 * Thrown when the runtime is asked to act but its own configuration is
 * incomplete — missing ANTHROPIC_API_KEY / OPENAI_API_KEY, an agent with
 * no flow, etc. Distinct from UpstreamUnavailable (provider was reachable
 * but failed) so health dashboards can tell "we broke it" from "they broke it".
 *
 * @api
 */
class Misconfigured extends RuntimeException {}
