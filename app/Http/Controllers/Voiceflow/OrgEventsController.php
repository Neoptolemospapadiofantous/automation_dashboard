<?php

namespace App\Http\Controllers\Voiceflow;

use App\Http\Controllers\Controller;
use App\Models\VoiceflowProjectPoolEntry;
use App\Models\VoiceflowWebhookEvent;
use App\Services\Voiceflow\Webhooks\SvixVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inbound Voiceflow org-events webhook receiver.
 *
 * Voiceflow sends `organization.project.*` events (created, deleted,
 * environment.created, environment.published, environment.merged,
 * environment.deleted) per organization once subscribed in the dashboard.
 *
 * Auth: shared platform-level secret in `services.voiceflow.org_webhook_secret`
 *       (compared with hash_equals). Svix HMAC will be added when `svix/svix`
 *       is brought in as a composer dep — until then the shared secret is
 *       the trust boundary and the route should be considered same-tier
 *       as a deploy-time configured value.
 *
 * Reactivity:
 *   - `organization.project.deleted` → mark pool entries retired (the project
 *     no longer exists; allocating to a new agent would 404).
 *   - other events → persisted but no-op.
 */
class OrgEventsController extends Controller
{
    public function __invoke(Request $request, SvixVerifier $svix): JsonResponse
    {
        $signatureValid = $this->verifySvix($request, $svix) || $this->verifySecret($request);
        abort_unless($signatureValid, 401, 'Bad org webhook signature');

        $envelope = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'time' => ['nullable', 'integer'],
            'resource' => ['nullable', 'string'],
        ]);

        $eventType = $envelope['type'];
        $data = $envelope['data'];
        $projectId = is_string($data['projectID'] ?? null) ? $data['projectID'] : null;
        $eventId = sprintf(
            '%s:%s:%s',
            $projectId ?? 'unknown',
            $envelope['time'] ?? '',
            $eventType,
        );

        return DB::transaction(function () use ($eventType, $eventId, $projectId, $envelope): JsonResponse {
            $event = VoiceflowWebhookEvent::query()->firstOrCreate(
                ['agent_id' => null, 'event_id' => $eventId],
                [
                    'team_id' => null,
                    'event_type' => $eventType,
                    'voiceflow_user_id' => null,
                    'voiceflow_session_key' => null,
                    'payload' => $envelope,
                    'signature_valid' => true,
                    'received_at' => now(),
                ],
            );

            if ($eventType === 'organization.project.deleted' && $projectId !== null) {
                // Driver-agnostic: per-row update so the notes-append works the same
                // on SQLite / MySQL / Postgres. The pool is small and project-delete
                // events are rare; the N+1 here is acceptable.
                $now = now()->toDateTimeString();
                $rows = VoiceflowProjectPoolEntry::query()
                    ->where('voiceflow_project_id', $projectId)
                    ->whereIn('status', ['available', 'assigned'])
                    ->get();
                foreach ($rows as $row) {
                    $row->status = 'retired';
                    $row->notes = ($row->notes !== null && $row->notes !== '' ? $row->notes."\n" : '')."Upstream deleted {$now}";
                    $row->save();
                }
            }

            $event->forceFill(['processed_at' => now()])->save();

            return response()->json(['ok' => true, 'event_id' => $event->id]);
        });
    }

    protected function verifySecret(Request $request): bool
    {
        $provided = $request->header('X-Voiceflow-Org-Secret', '');
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $expected = (string) config('services.voiceflow.org_webhook_secret', '');
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Try Svix-style verification first. Voiceflow's org-events spec routes
     * through Svix (`svix-id` / `svix-timestamp` / `svix-signature` headers).
     */
    protected function verifySvix(Request $request, SvixVerifier $svix): bool
    {
        $msgId = $request->header('svix-id', '');
        $ts = $request->header('svix-timestamp', '');
        $sig = $request->header('svix-signature', '');

        if (! is_string($msgId) || ! is_string($ts) || ! is_string($sig)) {
            return false;
        }
        if ($msgId === '' || $ts === '' || $sig === '') {
            return false;
        }

        $secret = (string) config('services.voiceflow.svix_secret', '');
        if ($secret === '') {
            return false;
        }

        return $svix->verify($secret, $msgId, $ts, $sig, $request->getContent());
    }
}
