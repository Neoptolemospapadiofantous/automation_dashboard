<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\Team;
use InvalidArgumentException;

/**
 * Set the team's current_agent_id. Mirrors Jetstream's switch-team pattern.
 *
 * The check that the agent belongs to the team lives in Team::switchAgent;
 * this action exists so HTTP/CLI callers all go through one path that we
 * can later extend (record an audit row, fire an event, etc).
 */
class SwitchAgent
{
    public function execute(Team $team, Agent $agent): Team
    {
        if (! $team->switchAgent($agent)) {
            throw new InvalidArgumentException(
                "Agent #{$agent->id} does not belong to team #{$team->id}."
            );
        }

        return $team->refresh();
    }
}
