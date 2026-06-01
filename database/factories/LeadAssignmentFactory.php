<?php

namespace Database\Factories;

use App\Enums\AssignmentStrategy;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAssignment>
 */
class LeadAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'team_id' => Team::factory(),
            'assigned_to' => null,
            'assigned_by' => null,
            'previous_assignee' => null,
            'strategy' => AssignmentStrategy::Manual,
        ];
    }
}
