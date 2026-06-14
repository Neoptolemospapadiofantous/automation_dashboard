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
 * X-Frame-Options is set ONLY IF ABSENT: the embed chat page
 * (EmbedController::chat) is an iframe product — it deliberately sends
 * `Content-Security-Policy: frame-ancestors *` + `X-Frame-Options:
 * ALLOWALL` so customers can frame it from any domain. Overriding that
 * here would silently break every installed widget.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
