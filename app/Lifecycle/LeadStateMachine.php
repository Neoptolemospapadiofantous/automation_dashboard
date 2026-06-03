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
 *   new ─┬─► engaging ─┬─► qualified ─► assigned ─┬─► won
 *        │             │                          └─► lost
 *        └─► lost      └─► lost
 *        └─► won (rare: a contact already converts on first touch)
 *
 * Rules:
 * - Won/lost are terminal — no reopening (use a fresh Lead row instead).
 * - Going forward is allowed; explicit demotions are not (a regressed
 *   "qualified" → "engaging" is almost always a bug, not a feature).
 * - Cross-step jumps (new → assigned) are allowed because the kanban UI
 *   lets reps drag cards across columns; auto-status from the Voiceflow
 *   webhook is still gated by its own logic.
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
            $forward(LeadStatus::New, LeadStatus::Engaging),
            $forward(LeadStatus::New, LeadStatus::Qualified, LeadQualified::class),
            $forward(LeadStatus::New, LeadStatus::Assigned, LeadAssigned::class),
            $forward(LeadStatus::New, LeadStatus::Lost, LeadLost::class),
            $forward(LeadStatus::New, LeadStatus::Won, LeadWon::class),

            // From Engaging
            $forward(LeadStatus::Engaging, LeadStatus::Qualified, LeadQualified::class),
            $forward(LeadStatus::Engaging, LeadStatus::Assigned, LeadAssigned::class),
            $forward(LeadStatus::Engaging, LeadStatus::Lost, LeadLost::class),
            $forward(LeadStatus::Engaging, LeadStatus::Won, LeadWon::class),

            // From Qualified
            $forward(LeadStatus::Qualified, LeadStatus::Assigned, LeadAssigned::class),
            $forward(LeadStatus::Qualified, LeadStatus::Lost, LeadLost::class),
            $forward(LeadStatus::Qualified, LeadStatus::Won, LeadWon::class),

            // From Assigned
            $forward(LeadStatus::Assigned, LeadStatus::Won, LeadWon::class),
            $forward(LeadStatus::Assigned, LeadStatus::Lost, LeadLost::class),

            // Won/Lost are terminal — no outgoing transitions.
        ];
    }
}
