<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\VoiceflowWebhookEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator-facing view of inbound Voiceflow webhook events. Scoped to the
 * team — they only see events that fired on their own agents or against
 * their team's org-webhook secret.
 *
 * No write actions here; this is purely a "what's been arriving" log so
 * you can debug integration issues without reaching for tinker.
 */
class WebhookEventsController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        $filter = [
            'type' => (string) $request->query('type', ''),
            'state' => (string) $request->query('state', 'all'), // all | processed | pending | failed
        ];

        $query = VoiceflowWebhookEvent::query()
            ->where(function ($q) use ($team) {
                $q->where('team_id', $team->id)
                    ->orWhereHas('agent', fn ($q2) => $q2->where('team_id', $team->id));
            });

        if ($filter['type'] !== '') {
            $query->where('event_type', $filter['type']);
        }

        if ($filter['state'] === 'processed') {
            $query->whereNotNull('processed_at')->whereNull('processing_error');
        } elseif ($filter['state'] === 'pending') {
            $query->whereNull('processed_at');
        } elseif ($filter['state'] === 'failed') {
            $query->whereNotNull('processing_error');
        }

        $events = $query
            ->latest('received_at')
            ->limit(100)
            ->get()
            ->map(fn (VoiceflowWebhookEvent $e) => [
                'id' => $e->id,
                'event_id' => $e->event_id,
                'event_type' => $e->event_type,
                'agent_id' => $e->agent_id,
                'voiceflow_user_id' => $e->voiceflow_user_id,
                'voiceflow_transcript_id' => $e->voiceflow_transcript_id,
                'signature_valid' => (bool) $e->signature_valid,
                'received_at' => $e->received_at->toIso8601String(),
                'processed_at' => $e->processed_at?->toIso8601String(),
                'processing_error' => $e->processing_error,
                'state' => $this->stateFor($e),
                // Truncate large payloads for the list view; full payload
                // available in DB if anyone needs to dig deeper.
                'payload_preview' => $this->preview($e->payload),
            ]);

        // Distinct event types observed (for the filter dropdown).
        $eventTypes = VoiceflowWebhookEvent::query()
            ->where(function ($q) use ($team) {
                $q->where('team_id', $team->id)
                    ->orWhereHas('agent', fn ($q2) => $q2->where('team_id', $team->id));
            })
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        return Inertia::render('System/WebhookEvents', [
            'events' => $events,
            'event_types' => $eventTypes,
            'filter' => $filter,
            'counts' => [
                'total' => $query->count(),
            ],
        ]);
    }

    protected function stateFor(VoiceflowWebhookEvent $e): string
    {
        if ($e->processing_error !== null) {
            return 'failed';
        }
        if ($e->processed_at !== null) {
            return 'processed';
        }

        return 'pending';
    }

    /**
     * @param  mixed  $payload
     */
    protected function preview($payload): string
    {
        $json = is_array($payload) || is_object($payload)
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $payload;
        $json = (string) $json;

        return strlen($json) > 200 ? substr($json, 0, 197).'...' : $json;
    }
}
