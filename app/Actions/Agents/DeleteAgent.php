<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Provisioning\PoolAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Hard delete. Conversations/leads/messages with this agent_id get
 * nullOnDelete via the FK, so historical data is preserved but unlinked
 * from the (now gone) agent.
 *
 * For managed agents: release the corresponding pool entry. It does NOT
 * go back to "available" — the previous tenant's KB documents and
 * conversation state still live inside the Voiceflow project, so the
 * operator must wipe the project in Voiceflow before re-enabling that
 * pool row. PoolAllocator::release flips it to 'retired'.
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

            if ($agent->isManaged()) {
                app(PoolAllocator::class)->release($agent);
            }

            $agent->delete();

            if ($team->current_agent_id === $agent->id || $team->current_agent_id === null) {
                $fallback = $team->agents()->latest()->first();
                $team->forceFill(['current_agent_id' => $fallback?->id])->save();
            }
        });
    }
}
