<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'assigned_to' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'source' => fake()->randomElement(['manual', 'voiceflow', 'web', 'import']),
            'status' => fake()->randomElement(LeadStatus::cases()),
            'score' => fake()->numberBetween(0, 100),
            'voiceflow_user_id' => null,
            'captured' => null,
            'notes' => fake()->optional()->sentence(),
            'last_contacted_at' => fake()->optional()->dateTimeThisMonth(),
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
