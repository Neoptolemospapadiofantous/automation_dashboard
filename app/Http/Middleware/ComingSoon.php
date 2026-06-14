<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-launch gate. When config('app.coming_soon') is true, every user-facing
 * web route (including login/register) renders the "coming soon" page so no
 * one can access or sign up yet.
 *
 * Machine endpoints stay reachable so prod integrations keep working:
 *   - api/*       — health probe + the public stats the landing site reads
 *   - webhooks/*  — Stripe delivery
 *   - up          — Laravel's health route
 *
 * Flip COMING_SOON=false (Forge env) to open the app at launch.
 */
class ComingSoon
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.coming_soon') && ! $request->is('api/*', 'webhooks/*', 'up')) {
            return response()->view('coming-soon', status: 200);
        }

        return $next($request);
    }
}
