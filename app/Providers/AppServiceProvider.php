<?php

namespace App\Providers;

use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Knowledge\KnowledgeBase;
use App\Runtime\Tools\CaptureLeadTool;
use App\Runtime\Tools\EndSessionTool;
use App\Runtime\Tools\QueryKnowledgeTool;
use App\Runtime\Tools\RequestHandoffTool;
use App\Runtime\Tools\SetVariableTool;
use App\Runtime\Tools\ToolRegistry;
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
        // Runtime contract → the native engine. AgentRuntime is the only
        // engine; the contract stays as the seam for any future engine
        // (bind a dispatcher again the day a second engine exists).
        $this->app->singleton(Runtime::class, AgentRuntime::class);

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
            $registry->register($app->make(RequestHandoffTool::class));

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
