<?php

namespace App\Runtime\Support;

use App\Models\Team;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Session\ConversationContext;

/**
 * The one place that escalates a conversation to a human: flag the session
 * (the durable record the ops surface reads) and notify the team owner.
 *
 * Shared by two callers so they can never drift:
 *   - RequestHandoffTool — the LLM decides to escalate (visitor asked for a
 *     person, frustrated, out of scope).
 *   - FlowExecutor confidence gate — deterministic backstop when the KB has
 *     no confident answer and the model didn't escalate on its own.
 */
class EscalateToHuman
{
    /**
     * Flag the session for handoff and (best-effort) notify the owner.
     * Idempotent on the session flag — calling twice in a turn is harmless.
     */
    public function handle(ConversationContext $context, string $reason): void
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'unspecified';

        $vars = (array) ($context->session->variables ?? []);
        $vars['handoff_requested'] = true;
        $vars['handoff_reason'] = $reason;
        $context->session->variables = $vars;
        $context->session->save();

        // Make the "a teammate has been notified" promise TRUE: bell + email
        // to the team owner. Best-effort — a mail hiccup must not fail the
        // visitor's turn (the session flag above is the durable record).
        rescue(function () use ($context, $reason): void {
            $team = $context->agent->team;
            $owner = $team instanceof Team ? $team->owner : null;
            if ($owner instanceof User) {
                $owner->notify(new HandoffRequestedNotification(
                    agent: $context->agent,
                    visitorId: $context->session->visitor_id,
                    reason: $reason,
                ));
            }
        }, report: true);
    }
}
