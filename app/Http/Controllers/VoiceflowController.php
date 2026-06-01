<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Events\LeadMessage;
use App\Events\LeadSaved;
use App\Models\Conversation;
use App\Models\Lead;
use App\Services\ConversationRecorder;
use App\Services\VoiceflowService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Server-side proxy for the Voiceflow Dialog Manager API.
 *
 * The browser never talks to Voiceflow directly — it calls these endpoints,
 * the server adds the API key, advances the conversation, captures lead fields
 * from the agent's session variables, and broadcasts updates to the team.
 */
class VoiceflowController extends Controller
{
    public function __construct(
        protected VoiceflowService $voiceflow,
        protected ConversationRecorder $recorder,
    ) {}

    /**
     * Diagnostic: reports whether Voiceflow is configured, reachable, and
     * whether the key/version are accepted. Safe — exposes no secrets.
     */
    public function health(): JsonResponse
    {
        return response()->json($this->voiceflow->health());
    }

    /**
     * Start (or reset) a conversation. Returns a fresh user id + the opening
     * traces. Optionally pre-fills variables (e.g. an existing lead's details).
     */
    public function launch(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'variables' => ['nullable', 'array'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        $userId = $lead?->voiceflow_user_id ?: 'web-'.Str::uuid()->toString();

        $variables = $data['variables'] ?? [];
        if ($lead) {
            $variables = array_merge(array_filter([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
            ]), $variables);
        }

        $traces = $this->guard(fn () => $this->voiceflow->launch($userId, $variables));
        if ($traces instanceof JsonResponse) {
            return $traces;
        }

        // Remember the Voiceflow user id on the lead for continuity.
        if ($lead && $lead->voiceflow_user_id !== $userId) {
            $lead->update(['voiceflow_user_id' => $userId]);
        }

        return $this->respond($request, $userId, $lead, $traces);
    }

    /**
     * Send a user's text reply and advance the conversation.
     */
    public function interact(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        // Persist + echo the user's message live before we hit Voiceflow.
        // Recording is best-effort: a storage hiccup must never break the chat.
        $conversation = $this->safelyResolve($request, $data['user_id'], $lead?->id);
        if ($conversation) {
            $this->safelyRecord($conversation, 'user', $data['message']);
        }
        $this->broadcastMessage($request, $lead, 'user', $data['message']);

        $traces = $this->guard(fn () => $this->voiceflow->sendText($data['user_id'], $data['message']));
        if ($traces instanceof JsonResponse) {
            return $traces;
        }

        return $this->respond($request, $data['user_id'], $lead, $traces, $conversation);
    }

    /**
     * Shared response builder: parse traces, broadcast agent messages, capture
     * lead fields from session variables, upsert the lead.
     */
    protected function respond(Request $request, string $userId, ?Lead $lead, array $traces, ?Conversation $conversation = null): JsonResponse
    {
        $parsed = $this->voiceflow->parseTraces($traces);

        $conversation ??= $this->safelyResolve($request, $userId, $lead?->id);

        foreach ($parsed['messages'] as $message) {
            if ($conversation) {
                $this->safelyRecord($conversation, 'agent', $message);
            }
            $this->broadcastMessage($request, $lead, 'agent', $message);
        }

        if ($conversation && $parsed['ended']) {
            try {
                $this->recorder->end($conversation);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Read the captured lead fields out of the agent's session.
        $fields = [];
        try {
            $fields = $this->voiceflow->extractLeadFields(
                $this->voiceflow->getVariables($userId)
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $lead = $this->upsertLead($request, $lead, $userId, $fields);

        // Keep the conversation linked to the lead once we know it.
        if ($conversation && $lead && $conversation->lead_id !== $lead->id) {
            try {
                $conversation->update(['lead_id' => $lead->id]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'conversation_id' => $conversation?->id,
            'user_id' => $userId,
            'lead_id' => $lead?->id,
            'messages' => $parsed['messages'],
            'buttons' => $parsed['buttons'],
            'ended' => $parsed['ended'],
            'captured' => $fields,
        ]);
    }

    /**
     * Create or update the lead from captured fields. Requires at least a name
     * or email before a lead row is created, to avoid empty placeholder leads.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function upsertLead(Request $request, ?Lead $lead, string $userId, array $fields): ?Lead
    {
        $team = $request->user()->currentTeam;

        if (! $lead) {
            // Reuse an existing lead for this Voiceflow session if present.
            $lead = Lead::where('team_id', $team->id)
                ->where('voiceflow_user_id', $userId)
                ->first();
        }

        if (! $lead && empty($fields['name']) && empty($fields['email'])) {
            return null; // Nothing identifying yet.
        }

        $attributes = array_merge($fields, [
            'voiceflow_user_id' => $userId,
            'last_contacted_at' => now(),
        ]);

        if ($lead) {
            $merged = array_merge($lead->captured ?? [], $fields);
            $lead->fill($attributes);
            $lead->captured = $merged;
            if ($lead->status === LeadStatus::New) {
                $lead->status = LeadStatus::Engaging;
            }
            $lead->save();
        } else {
            $lead = Lead::create([
                ...$attributes,
                'team_id' => $team->id,
                'name' => $fields['name'] ?? ($fields['email'] ?? 'New contact'),
                'source' => 'voiceflow',
                'status' => LeadStatus::Engaging,
                'captured' => $fields,
            ]);
        }

        broadcast(new LeadSaved($lead))->toOthers();

        return $lead;
    }

    protected function broadcastMessage(Request $request, ?Lead $lead, string $role, string $text): void
    {
        broadcast(new LeadMessage(
            teamId: $request->user()->currentTeam->id,
            leadId: $lead?->id,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
        ));
    }

    /**
     * Resolve (find-or-create) the conversation without ever breaking the chat
     * if storage fails. Returns null on failure (logged).
     */
    protected function safelyResolve(Request $request, string $userId, ?int $leadId): ?Conversation
    {
        try {
            return $this->recorder->resolve(
                $request->user()->currentTeam->id,
                $userId,
                $leadId,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Record a message best-effort; a storage failure is logged, not fatal.
     */
    protected function safelyRecord(Conversation $conversation, string $role, string $text): void
    {
        try {
            $this->recorder->record($conversation, $role, $text, 'text');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Resolve a lead from the request, scoped to the user's current team.
     */
    protected function resolveLead(Request $request, ?int $leadId): ?Lead
    {
        if (! $leadId) {
            return null;
        }

        return Lead::where('team_id', $request->user()->currentTeam->id)
            ->find($leadId);
    }

    protected function abortIfUnconfigured(): void
    {
        abort_unless($this->voiceflow->isConfigured(), 503, 'Voiceflow is not configured.');
    }

    /**
     * Run a Voiceflow call, converting upstream failures into a JSON error the
     * UI can show (instead of a generic 500). Returns the call's result, or a
     * JsonResponse on failure.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|JsonResponse
     */
    protected function guard(callable $callback)
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            $status = $e->response->status();
            report($e);

            $hint = match ($status) {
                401, 403 => 'Voiceflow rejected the API key. Check VOICEFLOW_API_KEY (must be a VF.DM.* Dialog Manager key).',
                404 => 'Voiceflow returned 404. Check VOICEFLOW_VERSION_ID / project — the agent version may not be published.',
                429 => 'Voiceflow rate limit hit. Try again shortly.',
                default => 'Voiceflow request failed (HTTP '.$status.').',
            };

            return response()->json([
                'error' => $hint,
                'upstream_status' => $status,
            ], 502);
        } catch (ConnectionException $e) {
            report($e);

            return response()->json([
                'error' => 'Could not reach Voiceflow (network/timeout). Check the server has outbound HTTPS access.',
            ], 504);
        }
    }
}
