<?php

namespace App\Lifecycle;

use App\Enums\LeadStatus;
use App\Events\Domain\LeadAssigned;
use App\Events\Domain\LeadLost;
use App\Events\Domain\LeadQualified;
use App\Events\Domain\LeadStatusChanged;
use App\Events\Domain\LeadWon;

/**
 * Lead status flow:
 *
 *   new ─┬─► qualified ─► assigned ─┬─► won
 *        │                          └─► lost ──► (reopen) new
 *        └─► lost
 *        └─► won (rare: a contact already converts on first touch)
 *
 * Rules:
 * - Won is terminal — a closed-won deal never moves again, so win counts
 *   stay trustworthy.
 * - Lost can be reopened: lost → new, for a dead lead that comes back.
 * - One-step demotions are allowed so reps can undo a mis-drag:
 *   qualified → new and assigned → qualified. Bigger backward jumps
 *   (e.g. assigned → new) are still blocked — undo one step at a time.
 * - Cross-step forward jumps (new → assigned) are allowed because the
 *   kanban UI lets reps drag cards across columns; auto-status from the
 *   engine webhook is still gated by its own logic.
 */
class LeadStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        $forward = function (LeadStatus $from, LeadStatus $to, ?string $event = null) {
            return new Transition(from: $from, to: $to, event: $event ?? LeadStatusChanged::class);
        };

        return [
            // From New
            $forward(LeadStatus::New, LeadStatus::Qualified, LeadQualified::class),
            $forward(LeadStatus::New, LeadStatus::Assigned, LeadAssigned::class),
            $forward(LeadStatus::New, LeadStatus::Lost, LeadLost::class),
            $forward(LeadStatus::New, LeadStatus::Won, LeadWon::class),

            // From Qualified
            $forward(LeadStatus::Qualified, LeadStatus::Assigned, LeadAssigned::class),
            $forward(LeadStatus::Qualified, LeadStatus::Lost, LeadLost::class),
            $forward(LeadStatus::Qualified, LeadStatus::Won, LeadWon::class),

            // From Assigned
            $forward(LeadStatus::Assigned, LeadStatus::Won, LeadWon::class),
            $forward(LeadStatus::Assigned, LeadStatus::Lost, LeadLost::class),

            // One-step demotions — undo a mis-drag, one column at a time.
            $forward(LeadStatus::Qualified, LeadStatus::New),
            $forward(LeadStatus::Assigned, LeadStatus::Qualified),

            // Reopen a dead lead. Won stays terminal — no outgoing edges.
            $forward(LeadStatus::Lost, LeadStatus::New),
        ];
    }
}
