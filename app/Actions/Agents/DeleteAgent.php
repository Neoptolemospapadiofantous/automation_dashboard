<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

/**
 * Hard delete. Conversations/leads/messages with this agent_id get
 * nullOnDelete via the FK, so historical data is preserved but unlinked
 * from the (now gone) agent.
 *
 * If the deleted agent was the team's current_agent_id, fall back to any
 * remaining agent or null — null is fine because OnboardingState handles it.
 */
class DeleteAgent
{
    public function execute(Agent $agent): void
    {
        DB::transaction(function () use ($agent) {
            $team = $agent->team;

            $agent->delete();

            if ($team->current_agent_id === $agent->id || $team->current_agent_id === null) {
                $fallback = $team->agents()->latest()->first();
                $team->forceFill(['current_agent_id' => $fallback?->id])->save();
            }
        });
    }
}
