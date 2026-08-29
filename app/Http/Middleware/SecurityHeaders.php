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
 *  - Strict-Transport-Security         — HTTPS-only, on secure requests
 *
 * HSTS is sent ONLY on secure requests: RFC 6797 §7.2 forbids sending it
 * over plain HTTP, and local development on http://localhost would
 * otherwise pin a developer's browser to HTTPS for a year. `secure()` is
 * trustworthy here — production cookies come back flagged `secure` with
 * SESSION_SECURE_COOKIE unset, which only happens when the request is
 * seen as HTTPS.
 *
 * Deliberately NO `includeSubDomains` and NO `preload`:
 *   - app.flowstack.run has no subdomains, so includeSubDomains buys
 *     nothing today and silently breaks the first one added over plain
 *     HTTP later.
 *   - preload is the genuinely irreversible part (removal from the
 *     browser list takes months) and requires an apex + includeSubDomains
 *     policy anyway. It is a separate, deliberate decision.
 *
 * ROLLBACK: set HSTS_MAX_AGE=0. Browsers treat max-age=0 as "forget this
 * host", so the policy clears on the next HTTPS visit. That is why a
 * one-year max-age is safe to ship here — unlike preload, this is
 * reversible as long as we can still serve a response.
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

        // HTTPS only — see the class docblock.
        $maxAge = (int) config('session.hsts_max_age');
        if ($maxAge > 0 && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}");
        }

        // The embed chat page governs its own framing (frame-ancestors).
        if (! $response->headers->has('X-Frame-Options') && ! $request->routeIs('embed.chat')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
