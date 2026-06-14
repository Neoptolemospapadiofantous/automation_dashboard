<?php

namespace App\Http\Controllers\Concerns;

use App\Authorization\Role;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Tiny mixin used by controllers that gate actions by per-team role.
 *
 * Usage:
 *   $this->requireOwner($request, 'cancel a subscription');
 *   $this->requireCapability($request, fn (Role $r) => $r->canDeleteAgent(), 'delete the agent');
 *
 * The second arg is a short verb-phrase used in the 403 message so users
 * see "Only the team owner can cancel a subscription" instead of a
 * generic "unauthorized." It also doubles as a doc comment.
 */
trait AuthorizesByTeamRole
{
    protected function requireOwner(Request $request, string $action): void
    {
        $this->requireCapability($request, fn (Role $role): bool => $role === Role::Owner, $action);
    }

    protected function requireCapability(Request $request, \Closure $check, string $action): void
    {
        $user = $request->user();
        $team = $user?->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'You must belong to a team to perform this action.');
        }

        $role = Role::forUser($user, $team);
        if (! $check($role)) {
            abort(403, "Your role ({$role->label()}) doesn't permit you to {$action}. Contact your team owner.");
        }
    }
}
