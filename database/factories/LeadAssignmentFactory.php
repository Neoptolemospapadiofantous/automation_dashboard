<?php

namespace Database\Factories;

use App\Enums\AssignmentStrategy;
use App\Models\Lead;
use App\Models\LeadAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAssignment>
 */
class LeadAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Derive team_id + agent_id from the parent lead so the
            // assignment row can't end up in a different team than the lead
            // it's auditing. Previously a fresh Team::factory() here
            // produced cross-team rows that violated app invariants.
            'lead_id' => Lead::factory(),
            'team_id' => fn (array $attrs) => Lead::find($attrs['lead_id'])->team_id,
            'agent_id' => fn (array $attrs) => Lead::find($attrs['lead_id'])->agent_id,
            'assigned_to' => null,
            'assigned_by' => null,
            'previous_assignee' => null,
            'strategy' => AssignmentStrategy::Manual,
        ];
    }
}
