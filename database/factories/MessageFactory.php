<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        $conversation = Conversation::factory();

        return [
            'conversation_id' => $conversation,
            'team_id' => Team::factory(),
            'role' => fake()->randomElement(['user', 'agent']),
            'text' => fake()->sentence(),
            'trace_type' => 'text',
            'sequence' => 1,
            'sent_at' => now(),
        ];
    }
}
