<?php

namespace App\Listeners;

use App\Events\Domain\AgentHealthChecked;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AgentUnhealthyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Surfaces health-probe failures to the team owner — but only on the
 * healthy → unhealthy *transition*, not every probe. Persistently-broken
 * agents won't spam.
 *
 * The Agent model is updated to reflect the new health state on every
 * probe; this listener fires AFTER. We compare the previous state
 * (stored on the Agent before the event saved it) against the current.
 */
class NotifyOnAgentHealthChange implements ShouldQueue
{
    public function handle(AgentHealthChecked $event): void
    {
        // Only notify on a fresh failure. If `last_health_ok` was already
        // false before this probe, it's still broken — operator was
        // already told.
        if ($event->ok) {
            return;
        }

        $agent = $event->agent->fresh();
        if ($agent === null) {
            return;
        }

        // Only notify on a fresh failure. wasOk null means "first probe"
        // (still worth telling the operator); false means "already broken,
        // skip the second alert."
        if ($event->wasOk === false) {
            return;
        }

        $team = $agent->team;
        if (! $team instanceof Team) {
            return;
        }

        $owner = $team->owner;
        if (! $owner instanceof User) {
            return;
        }

        $reason = (string) Str::of($event->result['reason'] ?? '')->limit(200);

        $owner->notify(new AgentUnhealthyNotification(
            agent: $agent,
            reason: $reason,
        ));
    }
}
