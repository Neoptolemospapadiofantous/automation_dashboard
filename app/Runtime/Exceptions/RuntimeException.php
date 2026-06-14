<?php

namespace App\Runtime\Exceptions;

use RuntimeException as BaseRuntimeException;

/**
 * Umbrella exception type for the Runtime contract.
 *
 * Every engine (AgentRuntime today; any future engine)
 * normalises its upstream errors into a subclass of this so controllers
 * can catch one type:
 *
 *   try {
 *       $traces = $runtime->launch($agent, $visitor);
 *   } catch (RuntimeException $e) {
 *       return response()->json(['error' => '...'], 503);
 *   }
 *
 * Subclasses:
 *   - Misconfigured       — our config is incomplete (missing API key, ...)
 *   - UpstreamUnavailable — an upstream provider call failed
 *
 * @api
 */
class RuntimeException extends BaseRuntimeException {}
