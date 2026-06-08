<?php

namespace App\Http\Controllers\Voiceflow;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\VoiceflowWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inbound Voiceflow session-lifecycle webhook receiver.
 *
 * Voiceflow sends `runtime.session.start|end` and `runtime.call.start|end` per
 * project once subscribed in the dashboard. There is no documented HMAC for
 * these events — auth is per-agent shared secret (the same `webhook_secret`
 * we already use for the Custom Action lead-capture webhook).
 *
 * The handler persists every event for idempotency + audit and updates the
 * corresponding Conversation reactively (replacing parts of the polling
 * backfill loop).
 */
class SessionLifecycleController extends Controller
{
    public function __invoke(Request $request, Agent $agent): JsonResponse
    {
        abort_unless($this->verifySecret($request, $agent), 401, 'Bad secret');
        abort_if($agent->status === 'disabled', 503, 'Agent disabled');

        $envelope = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'time' => ['nullable', 'integer'],
            'resource' => ['nullable', 'string'],
        ]);

        $eventType = $envelope['type'];
        $data = $envelope['data'];
        $eventId = (string) ($data['sessionID'] ?? $data['callSid'] ?? $data['userID'] ?? '').':'.($envelope['time'] ?? '').':'.$eventType;

        $voiceflowUserId = is_string($data['userID'] ?? null) ? $data['userID'] : null;
        $sessionKey = is_string($data['sessionID'] ?? null) ? $data['sessionID'] : null;

        return DB::transaction(function () use ($agent, $eventType, $data, $eventId, $voiceflowUserId, $sessionKey, $envelope): JsonResponse {
            // Idempotent insert: same (agent, event_id) returns existing row.
            $event = VoiceflowWebhookEvent::query()->firstOrCreate(
                ['agent_id' => $agent->id, 'event_id' => $eventId],
                [
                    'team_id' => $agent->team_id,
                    'event_type' => $eventType,
                    'voiceflow_user_id' => $voiceflowUserId,
                    'voiceflow_session_key' => $sessionKey,
                    'payload' => $envelope,
                    'signature_valid' => true,
                    'received_at' => now(),
                ],
            );

            // Reactively update the Conversation if we have a userID match.
            if ($voiceflowUserId !== null) {
                $convo = Conversation::query()
                    ->where('team_id', $agent->team_id)
                    ->where('voiceflow_user_id', $voiceflowUserId)
                    ->first();

                if ($convo !== null) {
                    $this->applyToConversation($convo, $eventType, $data);
                }
            }

            $event->forceFill(['processed_at' => now()])->save();

            return response()->json(['ok' => true, 'event_id' => $event->id]);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function applyToConversation(Conversation $convo, string $eventType, array $data): void
    {
        $updates = [];

        if ($eventType === 'runtime.session.start' && $convo->started_at === null) {
            $updates['started_at'] = now();
        }
        if (in_array($eventType, ['runtime.session.end', 'runtime.call.end'], true)) {
            $updates['ended_at'] = now();
            $updates['status'] = 'ended';
        }

        if (isset($data['transcriptID']) && is_string($data['transcriptID']) && $convo->voiceflow_transcript_id === null) {
            $updates['voiceflow_transcript_id'] = $data['transcriptID'];
        }

        if ($updates !== []) {
            $convo->forceFill($updates)->save();
        }
    }

    protected function verifySecret(Request $request, Agent $agent): bool
    {
        $provided = $request->header('X-Webhook-Secret', '');
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $expected = (string) $agent->webhook_secret;
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
