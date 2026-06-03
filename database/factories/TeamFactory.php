<?php

namespace Database\Factories;

use App\Billing\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'user_id' => User::factory(),
            'personal_team' => true,
            // Mirror the production backfill (every new team starts on Free
            // with its monthly allotment). Tests that need a depleted team
            // can override via ->state(['credit_balance' => 0]).
            'plan' => Plan::Free->value,
            'credit_balance' => Plan::Free->monthlyCredits(),
            'credits_renewed_at' => now(),
        ];
    }

    public function outOfCredits(): self
    {
        return $this->state(['credit_balance' => 0]);
    }

    public function onPlan(Plan $plan): self
    {
        return $this->state([
            'plan' => $plan->value,
            'credit_balance' => $plan->monthlyCredits(),
        ]);
    }
}
