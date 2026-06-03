<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->company().' Agent',
            'slug' => 'agent-'.Str::lower(Str::random(10)),
            'voiceflow_api_key' => 'VF.DM.'.Str::random(20),
            'voiceflow_project_id' => Str::lower(Str::random(24)),
            'voiceflow_environment' => 'main',
            'voiceflow_workspace_api_key' => null,
            'webhook_secret' => Str::random(40),
            'status' => Agent::STATUS_ACTIVE,
            'last_health_check_at' => null,
            'last_health_ok' => false,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => Agent::STATUS_DRAFT]);
    }

    public function disabled(): self
    {
        return $this->state(['status' => Agent::STATUS_DISABLED]);
    }

    /**
     * No credentials set — the "user just created an agent but hasn't
     * pasted the keys yet" state.
     */
    public function unconfigured(): self
    {
        return $this->state([
            'status' => Agent::STATUS_DRAFT,
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);
    }
}
