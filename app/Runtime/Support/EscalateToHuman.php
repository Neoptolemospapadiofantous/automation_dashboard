<?php

namespace App\Runtime\Support;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Session\ConversationContext;

/**
 * The one place that escalates a conversation to a human: flag the session
 * AND the Conversation row (the ops surface reads the latter), then notify
 * the team owner with enough context to act — deep link, the visitor's last
 * message, and whether contact details exist for follow-up.
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
     * Flag the session + conversation for handoff and (best-effort) notify
     * the owner. Idempotent — calling twice in a turn is harmless.
     */
    public function handle(ConversationContext $context, string $reason): void
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'unspecified';

        $vars = (array) ($context->session->variables ?? []);
        $alreadyFlagged = (bool) ($vars['handoff_requested'] ?? false);
        $vars['handoff_requested'] = true;
        $vars['handoff_reason'] = $reason;
        $context->session->variables = $vars;
        $context->session->save();

        // Durable, queryable record for the Conversations UI ("Needs human"
        // badge/filter + takeover banner). Session flags alone are invisible
        // to the ops surface.
        $conversation = $this->conversation($context);
        if ($conversation !== null) {
            $meta = (array) ($conversation->meta ?? []);
            $meta['handoff_requested'] = true;
            $meta['handoff_reason'] = $reason;
            $meta['handoff_at'] = now()->toIso8601String();
            $conversation->meta = $meta;
            $conversation->save();
        }

        // Make the "a teammate has been notified" promise TRUE: bell + email
        // to the team owner. Best-effort — a mail hiccup must not fail the
        // visitor's turn. Only on the FIRST flag per session, so a long
        // escalated conversation doesn't spam the owner every turn.
        if ($alreadyFlagged) {
            return;
        }
        rescue(function () use ($context, $reason, $conversation): void {
            $team = $context->agent->team;
            $owner = $team instanceof Team ? $team->owner : null;
            if ($owner instanceof User) {
                $owner->notify(new HandoffRequestedNotification(
                    agent: $context->agent,
                    visitorId: $context->session->visitor_id,
                    reason: $reason,
                    conversationId: $conversation?->id,
                    lastMessage: $context->userMessage,
                    contact: $this->contactSummary($context),
                ));
            }
        }, report: true);
    }

    /**
     * Whether the team could actually follow up with this visitor outside
     * the widget: a captured lead with an email or phone.
     */
    public function hasContact(ConversationContext $context): bool
    {
        return $this->contactSummary($context) !== null;
    }

    /**
     * "Name · email · phone" summary of the captured contact, or null when
     * the visitor is anonymous.
     */
    public function contactSummary(ConversationContext $context): ?string
    {
        $lead = Lead::query()
            ->where('team_id', $context->agent->team_id)
            ->where('visitor_id', $context->session->visitor_id)
            ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('phone'))
            ->latest('id')
            ->first();

        if ($lead === null) {
            return null;
        }

        $bits = array_values(array_filter([
            (string) $lead->name,
            (string) $lead->email,
            (string) $lead->phone,
        ], fn (string $v): bool => $v !== ''));

        return $bits === [] ? null : implode(' · ', $bits);
    }

    private function conversation(ConversationContext $context): ?Conversation
    {
        return Conversation::query()
            ->where('team_id', $context->agent->team_id)
            ->where('visitor_id', $context->session->visitor_id)
            ->latest('id')
            ->first();
    }
}
