<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response:
 *
 *  - X-Content-Type-Options: nosniff   — stop MIME sniffing of responses
 *  - Referrer-Policy                   — don't leak full dashboard URLs
 *  - X-Frame-Options: SAMEORIGIN       — clickjacking guard for the app
 *
 * The embed chat page (EmbedController::chat) is an iframe PRODUCT and owns
 * its own framing policy via `Content-Security-Policy: frame-ancestors ...`
 * (plus `X-Frame-Options: ALLOWALL` only when unrestricted). We must NOT
 * backfill SAMEORIGIN there: for an allowlisted agent the controller
 * intentionally omits X-Frame-Options (XFO can't express a domain list) and
 * relies on frame-ancestors — a SAMEORIGIN backfill would block the
 * customer's own domain and silently break every restricted widget.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The embed chat page governs its own framing (frame-ancestors).
        if (! $response->headers->has('X-Frame-Options') && ! $request->routeIs('embed.chat')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
