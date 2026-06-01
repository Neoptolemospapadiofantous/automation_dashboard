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
     * Find or start the conversation for a Voiceflow user within a team.
     */
    public function resolve(int $teamId, string $voiceflowUserId, ?int $leadId = null, string $channel = 'agent'): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['team_id' => $teamId, 'voiceflow_user_id' => $voiceflowUserId],
            ['channel' => $channel, 'status' => 'active', 'started_at' => now(), 'last_message_at' => now()],
        );

        if ($leadId && $conversation->lead_id !== $leadId) {
            $conversation->lead_id = $leadId;
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * Append a single message to a conversation and keep counters in sync.
     *
     * @param  'user'|'agent'|'system'  $role
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        Conversation $conversation,
        string $role,
        string $text,
        ?string $traceType = null,
        ?array $payload = null,
    ): Message {
        return DB::transaction(function () use ($conversation, $role, $text, $traceType, $payload) {
            $sequence = (int) $conversation->messages()->lockForUpdate()->max('sequence') + 1;

            $message = $conversation->messages()->create([
                'team_id' => $conversation->team_id,
                'role' => $role,
                'text' => $text,
                'trace_type' => $traceType,
                'payload' => $payload,
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
     * Mark a conversation ended (e.g. on an `end` trace or inactivity sweep).
     */
    public function end(Conversation $conversation): void
    {
        if ($conversation->status !== 'ended') {
            $conversation->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
        }
    }
}
