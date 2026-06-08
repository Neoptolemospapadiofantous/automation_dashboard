<?php

namespace App\Providers;

use App\Services\VoiceflowService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve VoiceflowService per-request, scoped to the current team's
        // active Agent. Anything that asks for a VoiceflowService through DI
        // (controllers, recorders, etc.) automatically gets the right tenant's
        // credentials with no per-callsite plumbing.
        //
        // Fallback path: when there's no authenticated user OR the user's team
        // has no current agent (background jobs, artisan commands run pre-
        // onboarding), construct from .env config so the service stays usable.
        // Tests that need a specific tenant create an Agent fixture and the
        // binding picks it up automatically.
        $this->app->scoped(VoiceflowService::class, function ($app) {
            $agent = $app['auth']->user()?->currentTeam?->currentAgent;

            return $agent
                ? VoiceflowService::forAgent($agent)
                : new VoiceflowService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
