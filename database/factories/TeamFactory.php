<?php

namespace Database\Factories;

use App\Billing\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
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
            // Mirror production: every new team starts on the Free rung with
            // its (small) monthly allotment and no Stripe subscription. Tests
            // that need a depleted team can override via
            // ->state(['credit_balance' => 0]). Tests exercising PAID
            // behaviour (top-ups, sustained credit burn) must move the team
            // onto a real rung — Free is capped and cannot top up.
            'plan' => Plan::Free->value,
            'credit_balance' => Plan::Free->monthlyCredits(),
            'credits_renewed_at' => now(),
        ];
    }
}
