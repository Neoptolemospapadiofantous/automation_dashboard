<?php

namespace App\Providers;

use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Knowledge\KnowledgeBase;
use App\Runtime\RuntimeDispatcher;
use App\Runtime\Tools\CaptureLeadTool;
use App\Runtime\Tools\EndSessionTool;
use App\Runtime\Tools\QueryKnowledgeTool;
use App\Runtime\Tools\RequestHandoffTool;
use App\Runtime\Tools\SetVariableTool;
use App\Runtime\Tools\ToolRegistry;
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

        // Runtime contract → RuntimeDispatcher. Controllers + the embed
        // flow depend on the Runtime interface (Phase 8 wires that in);
        // the dispatcher picks the right engine per agent based on
        // agents.runtime_mode. Defaults to Voiceflow for backward
        // compatibility — only agents explicitly flipped to 'native'
        // touch the new code path.
        $this->app->bind(Runtime::class, RuntimeDispatcher::class);

        // Native runtime wiring: the RAG store and the tool registry with
        // every built-in tool registered. Both singletons — stateless
        // (the registry holds tool instances, the store holds clients).
        $this->app->singleton(KnowledgeStore::class, KnowledgeBase::class);

        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            $registry = new ToolRegistry;
            $registry->register(new CaptureLeadTool);
            $registry->register($app->make(QueryKnowledgeTool::class));
            $registry->register(new EndSessionTool);
            $registry->register(new SetVariableTool);
            $registry->register(new RequestHandoffTool);

            return $registry;
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
