<?php

namespace App\Runtime\Support;

use App\Enums\LeadStatus;
use App\Models\Agent;
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
            // A handoff with no way to reach the visitor is a lost lead
            // recorded as a task (two sat unanswerable for a week). Mark
            // the conversation as waiting for contact so the next visitor
            // message is checked for an email or phone before anything
            // else — see captureContactReply().
            if (! $this->hasContact($context)) {
                $meta['handoff_awaiting_contact'] = true;
            }
            $conversation->meta = $meta;
            $conversation->save();

            // The escalating message itself may carry the contact ("call me
            // on 99123456, I need a person") — that message is never seen
            // by the waiting check, which only runs on the NEXT one. Capture
            // it now so an email in the same breath as the ask is not lost.
            if (($meta['handoff_awaiting_contact'] ?? false) && trim($context->userMessage) !== '') {
                rescue(
                    fn () => $this->captureContactReply($context->agent, $conversation, $context->session->visitor_id, $context->userMessage),
                    report: true,
                );
            }
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

    /**
     * The one line every escalation path appends when no contact is on
     * file. Deterministic and identical everywhere so a visitor is asked
     * the same way whether the chip, the tool or the backstop escalated.
     */
    public function contactAsk(): string
    {
        return 'So a teammate can reach you: what is the best email or phone number?';
    }

    /**
     * Called on every visitor message while the conversation is waiting
     * for contact. If the message carries an email or phone, upsert the
     * lead exactly as capture_lead would, clear the wait, and re-notify the
     * owner WITH the contact — quietly, without a second phone call.
     * Returns the lead, or null when the message carried no contact.
     */
    public function captureContactReply(Agent $agent, Conversation $conversation, string $visitorId, string $message): ?Lead
    {
        $email = self::extractEmail($message);
        $phone = self::extractPhone($message);
        if ($email === null && $phone === null) {
            return null;
        }

        $attributes = [
            'name' => '(no name)',
            'phone' => $phone,
            'status' => LeadStatus::New->value,
            'source' => 'chat',
            'score' => 0,
            'score_breakdown' => [],
            'captured' => array_keys(array_filter(['email' => $email, 'phone' => $phone])),
            'notes' => 'Left after asking for a human.',
            'visitor_id' => $visitorId,
        ];

        // Same dedupe rule as CaptureLeadTool: the email is the identity
        // when there is one, otherwise the chat session.
        $lead = $email !== null
            ? Lead::updateOrCreate(['team_id' => $agent->team_id, 'agent_id' => $agent->id, 'email' => $email], $attributes)
            : Lead::updateOrCreate(['team_id' => $agent->team_id, 'agent_id' => $agent->id, 'email' => null, 'visitor_id' => $visitorId], $attributes);

        $meta = (array) ($conversation->meta ?? []);
        $meta['handoff_awaiting_contact'] = false;
        $meta['handoff_contact_at'] = now()->toIso8601String();
        $conversation->meta = $meta;
        $conversation->lead_id = $conversation->lead_id ?? $lead->id;
        $conversation->save();

        rescue(function () use ($agent, $visitorId, $conversation, $message, $email, $phone): void {
            $team = $agent->team;
            $owner = $team instanceof Team ? $team->owner : null;
            if ($owner instanceof User) {
                $owner->notify(new HandoffRequestedNotification(
                    agent: $agent,
                    visitorId: $visitorId,
                    reason: 'Visitor left contact details after asking for a human.',
                    conversationId: $conversation->id,
                    lastMessage: $message,
                    contact: implode(' · ', array_filter([$email, $phone])),
                    ring: false,
                ));
            }
        }, report: true);

        return $lead;
    }

    public static function extractEmail(string $text): ?string
    {
        return preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m) === 1 ? strtolower($m[0]) : null;
    }

    /**
     * A phone number is at least 8 digits, optionally with a leading +, and
     * the usual separators — enough to catch "+357 97 606063" and "99123456"
     * without matching a price or a date.
     */
    public static function extractPhone(string $text): ?string
    {
        if (preg_match('/\+?\d[\d\s().-]{6,}\d/', $text, $m) !== 1) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $m[0]) ?? '';

        return strlen($digits) >= 8 ? trim($m[0]) : null;
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
