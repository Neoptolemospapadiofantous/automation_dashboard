<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Persists conversation turns to the local store (the operational source of
 * truth). Kept separate from the controller so the proxy, the capture webhook,
 * and any backfill job all record the same way.
 */
class ConversationRecorder
{
    /**
     * Find or start the conversation for a visitor within a team.
     *
     * Optional $agentId is stamped on creation and back-filled on existing
     * rows that pre-date multi-tenancy. The match is intentionally
     * (team_id, visitor_id) — adding agent_id would split a single visitor
     * session across rows if the team switched agents mid-chat.
     */
    public function resolve(int $teamId, string $visitorId, ?int $leadId = null, string $channel = 'agent', ?int $agentId = null, ?string $visitorToken = null): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['team_id' => $teamId, 'visitor_id' => $visitorId],
            [
                'agent_id' => $agentId,
                'channel' => $channel,
                'status' => 'active',
                'visitor_token' => $visitorToken,
                'started_at' => now(),
                'last_message_at' => now(),
            ],
        );

        $dirty = false;

        if ($leadId && $conversation->lead_id !== $leadId) {
            $conversation->lead_id = $leadId;
            $dirty = true;
        }

        if ($agentId && $conversation->agent_id !== $agentId) {
            $conversation->agent_id = $agentId;
            $dirty = true;
        }

        // Back-fill the stable identity on rows that pre-date it (the per-chat
        // visitor_id keys the row; the token groups a visitor's chats together).
        if ($visitorToken && $conversation->visitor_token !== $visitorToken) {
            $conversation->visitor_token = $visitorToken;
            $dirty = true;
        }

        if ($dirty) {
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * Append a single message to a conversation and keep counters in sync.
     *
     * @param  'user'|'agent'|'human'|'system'  $role  'human' = a team member
     *                                                 replying live during a takeover
     * @param  array<string, mixed>|null  $payload
     * @param  list<array<string, mixed>>|null  $citations  KB sources that grounded an agent answer
     */
    public function record(
        Conversation $conversation,
        string $role,
        string $text,
        ?string $traceType = null,
        ?array $payload = null,
        ?array $citations = null,
    ): Message {
        return DB::transaction(function () use ($conversation, $role, $text, $traceType, $payload, $citations) {
            $sequence = (int) $conversation->messages()->lockForUpdate()->max('sequence') + 1;

            $message = $conversation->messages()->create([
                'team_id' => $conversation->team_id,
                'agent_id' => $conversation->agent_id,
                'role' => $role,
                'text' => $text,
                'trace_type' => $traceType,
                'payload' => $payload,
                'citations' => $citations !== null && $citations !== [] ? $citations : null,
                'sequence' => $sequence,
                'sent_at' => now(),
            ]);

            $conversation->forceFill([
                'message_count' => $sequence,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });
    }

    /**
     * Store a visitor's post-chat rating and end the conversation.
     *
     * Rating is one of Conversation::RATINGS; the comment is optional. Ending
     * here matches the widget's "rate → reset" flow: a rated conversation is a
     * finished one, so the next launch threads a fresh row.
     */
    public function rate(Conversation $conversation, string $rating, ?string $comment = null): void
    {
        $comment = $comment !== null ? trim($comment) : null;

        $conversation->forceFill([
            'rating' => $rating,
            'feedback_comment' => $comment !== '' ? $comment : null,
            'rated_at' => now(),
        ])->save();

        $this->end($conversation);
    }

    /**
     * Mark a conversation ended (e.g. on an `end` trace or inactivity sweep).
     */
    public function end(Conversation $conversation): void
    {
        if ($conversation->status !== 'ended' && $conversation->canTransitionTo('ended')) {
            $conversation->forceFill(['ended_at' => now()])->save();
            // State machine fires ConversationEnded + StateChanged events
            // and persists the status change atomically.
            $conversation->transitionTo('ended');
        }
    }
}
