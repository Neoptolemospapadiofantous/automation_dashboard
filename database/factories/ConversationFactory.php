<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => null,
            'voiceflow_user_id' => 'web-'.fake()->uuid(),
            'channel' => 'agent',
            'status' => 'active',
            'message_count' => 0,
            'started_at' => now(),
            'last_message_at' => now(),
        ];
    }
}
