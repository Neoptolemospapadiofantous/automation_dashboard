<?php

namespace App\Providers;

use App\Services\VoiceflowService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
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
        // Branded verification email. Replaces Laravel's default
        // "Whoops" generic template with Flowstack copy + tone, while
        // keeping the signed URL Fortify generates.
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $name = (string) ($notifiable->name ?? 'there');

            return (new MailMessage)
                ->subject('Verify your email to activate Flowstack')
                ->greeting("Hi {$name},")
                ->line('Welcome to Flowstack. One quick step before you can use the dashboard: confirm this is your email.')
                ->action('Verify email', $url)
                ->line("If you didn't sign up, you can safely ignore this email — the verification link will expire automatically.");
        });
    }
}
