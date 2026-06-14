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
            'status' => Agent::STATUS_ACTIVE,
            'mode' => Agent::MODE_MANAGED,
            'runtime_mode' => Agent::RUNTIME_NATIVE,
            'last_health_check_at' => null,
            'last_health_ok' => false,
        ];
    }

    /**
     * @api consumed by the test suite (outside phpstan's scanned paths).
     */
    public function draft(): self
    {
        return $this->state(['status' => Agent::STATUS_DRAFT]);
    }
}
