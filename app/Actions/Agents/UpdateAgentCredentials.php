<?php

namespace App\Actions\Agents;

use App\Events\Domain\AgentHealthChecked;
use App\Models\Agent;
use App\Services\VoiceflowService;

/**
 * Save credentials, probe Voiceflow, and (if green) activate the agent.
 *
 * This is THE place the draft→active rule lives. The state machine's guard
 * also enforces it as a backstop, but this action does the actual work of
 * setting last_health_ok before requesting the transition.
 *
 * Idempotent: re-running with the same creds re-runs the health check
 * (useful for "is the agent still healthy?" pings).
 */
class UpdateAgentCredentials
{
    /**
     * @param  array{voiceflow_api_key?: string|null, voiceflow_project_id?: string|null, voiceflow_environment?: string|null, voiceflow_workspace_api_key?: string|null}  $credentials
     * @return array{agent: Agent, health: array<string, mixed>}
     */
    public function execute(Agent $agent, array $credentials): array
    {
        $agent->fill(array_filter([
            'voiceflow_api_key' => $credentials['voiceflow_api_key'] ?? null,
            'voiceflow_project_id' => $credentials['voiceflow_project_id'] ?? null,
            'voiceflow_environment' => $credentials['voiceflow_environment'] ?? null,
            'voiceflow_workspace_api_key' => $credentials['voiceflow_workspace_api_key'] ?? null,
        ], fn ($v) => $v !== null));

        $agent->save();

        $health = $this->runHealthCheck($agent);

        $agent->fill([
            'last_health_check_at' => now(),
            'last_health_ok' => (bool) ($health['ok'] ?? false),
        ])->save();

        event(new AgentHealthChecked($agent, (bool) ($health['ok'] ?? false), $health));

        // Transition draft → active when the health check passes. The state
        // machine refuses if the agent isn't isConfigured(), which double-
        // guards the unconfigured-but-somehow-ok path.
        if ($agent->status === Agent::STATUS_DRAFT && $agent->canTransitionTo(Agent::STATUS_ACTIVE)) {
            $agent->transitionTo(Agent::STATUS_ACTIVE);
        }

        return ['agent' => $agent->refresh(), 'health' => $health];
    }

    /**
     * @return array<string, mixed>
     */
    protected function runHealthCheck(Agent $agent): array
    {
        if (! $agent->isConfigured()) {
            return ['ok' => false, 'reason' => 'Agent has no API key or project id.'];
        }

        // For now the runtime construction reads from .env config. After
        // Phase B's VoiceflowService::forAgent($agent), this becomes:
        //   $service = VoiceflowService::forAgent($agent);
        // For Phase A purposes the health probe is a service concern; we
        // delegate to whatever the current VoiceflowService can do.
        $service = app(VoiceflowService::class);

        try {
            return $service->health();
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }
}
