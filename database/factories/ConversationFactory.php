<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => null,
            'visitor_id' => 'web-'.fake()->uuid(),
            'channel' => 'agent',
            'status' => 'active',
            'message_count' => 0,
            'started_at' => now(),
            'last_message_at' => now(),
        ];
    }
}
