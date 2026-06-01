<?php

namespace App\Services;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which rep a lead goes to and records the delegation.
 *
 * Strategies:
 *  - round_robin: the team member who was assigned least recently
 *  - least_loaded: the team member with the fewest open (assigned/qualified) leads
 *  - manual: an explicit target user
 */
class LeadDelegator
{
    /**
     * Assign a lead using a strategy, or to a specific user (manual).
     * Records an audit row and updates the lead. Returns the assigned user (or
     * null if unassigned / no eligible members).
     */
    public function assign(
        Lead $lead,
        AssignmentStrategy $strategy = AssignmentStrategy::RoundRobin,
        ?User $byUser = null,
        ?int $toUserId = null,
    ): ?User {
        return DB::transaction(function () use ($lead, $strategy, $byUser, $toUserId) {
            $team = $lead->team;

            $target = match ($strategy) {
                AssignmentStrategy::Manual => $toUserId ? $this->memberOrNull($team, $toUserId) : null,
                AssignmentStrategy::Unassigned => null,
                AssignmentStrategy::RoundRobin => $this->roundRobin($team),
                AssignmentStrategy::LeastLoaded => $this->leastLoaded($team),
            };

            $previous = $lead->assigned_to;

            // No change? Don't churn the audit log.
            if ($target?->id === $previous && $strategy !== AssignmentStrategy::Unassigned) {
                return $target;
            }

            $lead->assigned_to = $target?->id;
            // Auto-advance to "assigned" when a rep takes it.
            if ($target && in_array($lead->status, [LeadStatus::Qualified, LeadStatus::New, LeadStatus::Engaging], true)) {
                $lead->status = LeadStatus::Assigned;
            }
            $lead->save();

            LeadAssignment::create([
                'lead_id' => $lead->id,
                'team_id' => $team->id,
                'assigned_to' => $target?->id,
                'assigned_by' => $byUser?->id,
                'previous_assignee' => $previous,
                'strategy' => $strategy,
            ]);

            return $target;
        });
    }

    /**
     * Pick the member assigned least recently (true round-robin via the audit
     * log). Members who have never been assigned come first.
     */
    protected function roundRobin(Team $team): ?User
    {
        $members = $this->members($team);
        if ($members->isEmpty()) {
            return null;
        }

        // Most-recent assignment time per user within this team.
        $lastAssignedAt = LeadAssignment::query()
            ->where('team_id', $team->id)
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, MAX(created_at) as last_at')
            ->groupBy('assigned_to')
            ->pluck('last_at', 'assigned_to');

        // Sort: never-assigned (null) first, then oldest last_at.
        return $members
            ->sortBy(fn (User $u) => $lastAssignedAt[$u->id] ?? '0000-00-00 00:00:00')
            ->first();
    }

    /**
     * Pick the member with the fewest currently-open leads.
     */
    protected function leastLoaded(Team $team): ?User
    {
        $members = $this->members($team);
        if ($members->isEmpty()) {
            return null;
        }

        $openCounts = Lead::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [LeadStatus::Assigned->value, LeadStatus::Qualified->value])
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        return $members
            ->sortBy(fn (User $u) => $openCounts[$u->id] ?? 0)
            ->first();
    }

    /**
     * @return Collection<int, User>
     */
    protected function members(Team $team): Collection
    {
        return $team->allUsers();
    }

    protected function memberOrNull(Team $team, int $userId): ?User
    {
        return $this->members($team)->firstWhere('id', $userId);
    }
}
