<?php

namespace App\Actions\Agents;

use App\Billing\Exceptions\PlanLimitExceeded;
use App\Events\Domain\AgentCreated;
use App\Models\Agent;
use App\Models\Team;

/**
 * The single entry point for "make a new agent."
 *
 * Agents run on the native Flowstack runtime — there is no external
 * provisioning step. The agent is ACTIVE the moment the row exists;
 * its health depends only on the platform-level ANTHROPIC_API_KEY /
 * OPENAI_API_KEY (reported by AgentRuntime::health on the agent page).
 */
class CreateAgent
{
    public function execute(Team $team, string $name): Agent
    {
        // Plan limit guard. Throws BEFORE we touch the DB so we never leave
        // a half-created agent if the team hit their cap. The global
        // exception handler maps this to 403 for JSON and a flash-redirect
        // for web — see bootstrap/app.php.
        $plan = $team->planObject();
        $existing = $team->agents()->count();
        if ($existing >= $plan->maxAgents()) {
            throw new PlanLimitExceeded(
                resource: 'agents',
                limit: $plan->maxAgents(),
                plan: $plan,
            );
        }

        $effectiveName = $name !== '' ? $name : $this->defaultName($team);

        /** @var Agent $agent */
        $agent = $team->agents()->create([
            'name' => $effectiveName,
            'mode' => Agent::MODE_MANAGED,
            'runtime_mode' => Agent::RUNTIME_NATIVE,
            'status' => Agent::STATUS_ACTIVE,
            'last_health_check_at' => now(),
            'last_health_ok' => true,
        ]);

        event(new AgentCreated($agent));

        // If this is the team's first agent, make it current so the next page
        // load doesn't bounce back through OnboardingState::NeedsAgent.
        if (! $team->current_agent_id) {
            $team->forceFill(['current_agent_id' => $agent->id])->save();
        }

        return $agent->refresh();
    }

    protected function defaultName(Team $team): string
    {
        $existing = $team->agents()->count();

        return $existing === 0 ? 'Default agent' : 'Agent '.($existing + 1);
    }
}
