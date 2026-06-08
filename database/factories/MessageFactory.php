<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Derive team_id + agent_id from the parent conversation via
            // closures so the message can never end up in a different team
            // than its conversation. Previously the factory created a fresh
            // Team::factory() here, which silently violated the denormalised
            // invariant the app relies on (messages.team_id == conversations.team_id).
            'conversation_id' => Conversation::factory(),
            'team_id' => fn (array $attrs) => Conversation::find($attrs['conversation_id'])->team_id,
            'agent_id' => fn (array $attrs) => Conversation::find($attrs['conversation_id'])->agent_id,
            'role' => fake()->randomElement(['user', 'agent']),
            'text' => fake()->sentence(),
            'trace_type' => 'text',
            'sequence' => 1,
            'sent_at' => now(),
        ];
    }

    public function fromUser(): self
    {
        return $this->state(['role' => 'user']);
    }

    public function fromAgent(): self
    {
        return $this->state(['role' => 'agent']);
    }
}
