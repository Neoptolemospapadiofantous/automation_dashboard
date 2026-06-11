<?php

namespace App\Providers;

use App\Models\Agent;
use App\Services\Voiceflow\Client\AnalyticsClient;
use App\Services\Voiceflow\Client\RealtimeClient;
use App\Services\Voiceflow\Client\RuntimeClient;
use App\Services\Voiceflow\Client\StreamingClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Voiceflow subclient layer into the container.
 *
 * Bindings:
 *   - VoiceflowHttpClient — singleton (stateless factory).
 *   - RuntimeClient — request-scoped, resolved against the current Agent.
 *     Use `app(RuntimeClient::class)` inside a request to get the
 *     per-tenant client; outside a request (CLI, jobs), build with
 *     `Voiceflow::runtimeFor($agent)` instead.
 *
 * Future: AnalyticsClient, RealtimeClient, StreamingClient bind here too.
 */
class VoiceflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VoiceflowHttpClient::class);

        $this->app->scoped(AnalyticsClient::class, function ($app): AnalyticsClient {
            return self::analyticsFor($this->resolveCurrentAgent(), $app->make(VoiceflowHttpClient::class));
        });

        $this->app->scoped(RealtimeClient::class, function ($app): RealtimeClient {
            return self::realtimeFor($this->resolveCurrentAgent(), $app->make(VoiceflowHttpClient::class));
        });

        $this->app->scoped(StreamingClient::class, function ($app): StreamingClient {
            return self::streamingFor($this->resolveCurrentAgent(), $app->make(VoiceflowHttpClient::class));
        });
    }

    public static function streamingFor(?Agent $agent, ?VoiceflowHttpClient $http = null): StreamingClient
    {
        $http ??= app(VoiceflowHttpClient::class);
        $runtime = self::runtimeFor($agent, $http);

        $projectId = $agent !== null
            ? (string) $agent->voiceflow_project_id
            : (string) config('services.voiceflow.project_id', '');
        $environment = $agent !== null
            ? (string) $agent->voiceflow_environment
            : (string) config('services.voiceflow.environment', 'main');

        return new StreamingClient(
            runtime: $runtime,
            baseUrl: (string) config('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.com'),
            projectId: $projectId,
            environment: $environment,
        );
    }

    /**
     * Build a RuntimeClient for a specific Agent (or fall back to env config
     * when no agent is present). Use from CLI / jobs where the container can't
     * resolve the current request user.
     */
    public static function runtimeFor(?Agent $agent, ?VoiceflowHttpClient $http = null): RuntimeClient
    {
        $http ??= app(VoiceflowHttpClient::class);

        $apiKey = $agent !== null ? (string) $agent->voiceflow_api_key : (string) config('services.voiceflow.api_key', '');
        $projectId = $agent !== null ? (string) $agent->voiceflow_project_id : (string) config('services.voiceflow.project_id', '');
        $environment = $agent !== null ? (string) $agent->voiceflow_environment : (string) config('services.voiceflow.environment', 'main');
        $workspaceKey = $agent !== null ? $agent->voiceflow_workspace_api_key : config('services.voiceflow.workspace_api_key');

        return new RuntimeClient(
            http: $http,
            baseUrl: (string) config('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.com'),
            apiKey: $apiKey,
            projectId: $projectId,
            environment: $environment,
            workspaceApiKey: $workspaceKey !== null ? (string) $workspaceKey : null,
        );
    }

    public static function analyticsFor(?Agent $agent, ?VoiceflowHttpClient $http = null): AnalyticsClient
    {
        $http ??= app(VoiceflowHttpClient::class);

        $workspaceKey = self::workspaceKeyFor($agent);
        $projectId = $agent !== null ? (string) $agent->voiceflow_project_id : (string) config('services.voiceflow.project_id', '');

        return new AnalyticsClient(
            http: $http,
            baseUrl: (string) config('services.voiceflow.analytics_url', 'https://analytics-api.voiceflow.com'),
            workspaceApiKey: $workspaceKey,
            projectId: $projectId,
        );
    }

    public static function realtimeFor(?Agent $agent, ?VoiceflowHttpClient $http = null): RealtimeClient
    {
        $http ??= app(VoiceflowHttpClient::class);

        return new RealtimeClient(
            http: $http,
            baseUrl: (string) config('services.voiceflow.realtime_url', 'https://realtime-api.voiceflow.com'),
            workspaceApiKey: self::workspaceKeyFor($agent),
        );
    }

    /**
     * Resolve the workspace key with the documented fallback to the DM key,
     * matching legacy VoiceflowService::workspaceKey().
     */
    protected static function workspaceKeyFor(?Agent $agent): string
    {
        if ($agent !== null) {
            $workspace = $agent->voiceflow_workspace_api_key;
            if ($workspace !== null && (string) $workspace !== '') {
                return (string) $workspace;
            }

            $dm = $agent->voiceflow_api_key;
            if ($dm !== null && (string) $dm !== '') {
                return (string) $dm;
            }
        }

        $configWorkspace = config('services.voiceflow.workspace_api_key');
        if ($configWorkspace !== null && (string) $configWorkspace !== '') {
            return (string) $configWorkspace;
        }

        return (string) config('services.voiceflow.api_key', '');
    }

    protected function resolveCurrentAgent(): ?Agent
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $team = $user->currentTeam;
        if ($team === null) {
            return null;
        }

        $teamId = (int) $team->getKey();
        $agentId = $team->getAttribute('current_agent_id');
        if ($agentId === null) {
            return null;
        }

        return Agent::query()->where('team_id', $teamId)->find($agentId);
    }
}
