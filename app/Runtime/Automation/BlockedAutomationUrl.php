<?php

namespace App\Runtime\Automation;

use RuntimeException;

/**
 * Thrown when an automation's target URL fails the SSRF guard — bad scheme,
 * embedded credentials, an unresolvable host, or a host that resolves to a
 * private / loopback / link-local / reserved address (incl. the cloud
 * metadata endpoint 169.254.169.254).
 *
 * The caller turns this into an is_error tool_result; the request is NEVER
 * sent. The message is operator-facing (surfaced in automation_runs), so it
 * names the reason without leaking internal addresses to the visitor.
 */
class BlockedAutomationUrl extends RuntimeException {}
