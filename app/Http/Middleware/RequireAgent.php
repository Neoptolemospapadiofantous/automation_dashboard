<?php

namespace App\Http\Middleware;

use App\Lifecycle\OnboardingState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force-redirects authenticated users who haven't finished onboarding into
 * the wizard. The whole protected app group (dashboard, leads, conversations,
 * chat, KB, stats) lives behind this; routes the user needs to BE on while
 * unboarded (onboarding.*, agents.*, profile.*) are explicitly bypassed.
 *
 * Single source of truth for "where should this user go right now" is
 * OnboardingState::for($user) — see app/Lifecycle/OnboardingState.php.
 */
class RequireAgent
{
    /**
     * Route name prefixes that must remain reachable even when onboarding
     * is incomplete — otherwise the wizard, agent creation page, and
     * profile/logout become unreachable.
     */
    protected const BYPASS_PREFIXES = [
        'onboarding.',
        'agents.',
        'current-agent.',
        'profile.',
        'two-factor.',
        'api-tokens.',
        'teams.',
        'current-team.',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->isBypassRoute($request)) {
            return $next($request);
        }

        $state = OnboardingState::for($user);

        if ($state->isComplete()) {
            return $next($request);
        }

        $target = $state->nextRoute();

        // No route to bounce to (e.g. NeedsTeam without a teams.create route
        // wired) → let the request through rather than infinite-loop.
        if ($target === null || ! Route::has($target)) {
            return $next($request);
        }

        return redirect()->route($target);
    }

    protected function isBypassRoute(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) || $name === rtrim($prefix, '.')) {
                return true;
            }
        }

        return false;
    }
}
