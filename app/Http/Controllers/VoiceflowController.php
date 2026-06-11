<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Enums\LeadStatus;
use App\Events\LeadMessage;
use App\Events\LeadSaved;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Models\RuntimeSession;
use App\Services\ConversationRecorder;
use App\Services\Voiceflow\Client\StreamingClient;
use App\Services\VoiceflowService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side chat proxy for the dashboard's Chat page.
 *
 * The browser never talks to the conversational engine directly — it calls
 * these endpoints, the server advances the conversation, records the
 * transcript, debits credits, and broadcasts updates to the team.
 *
 * Two engines live behind this controller:
 *  - native (runtime_mode='native'): routed through the Runtime dispatcher;
 *    lead capture happens INSIDE the engine via the capture_lead tool, so
 *    the Voiceflow-specific variable extraction is skipped.
 *  - voiceflow (default): the legacy Dialog Manager proxy, untouched.
 */
class VoiceflowController extends Controller
{
    public function __construct(
        protected VoiceflowService $voiceflow,
        protected ConversationRecorder $recorder,
        protected CreditMeter $credits,
        protected Runtime $runtime,
    ) {}

    /**
     * Diagnostic: reports whether the current agent's engine is configured
     * and reachable. Safe — exposes no secrets.
     */
    public function health(Request $request): JsonResponse
    {
        if ($agent = $this->nativeAgent($request)) {
            return response()->json($this->runtime->health($agent));
        }

        return response()->json($this->voiceflow->health());
    }

    /**
     * Start (or reset) a conversation. Returns a fresh user id + the opening
     * traces. Optionally pre-fills variables (e.g. an existing lead's details).
     */
    public function launch(Request $request): JsonResponse
    {
        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeLaunch($request, $agent);
        }

        $this->abortIfUnconfigured();

        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

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
        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeInteract($request, $agent);
        }

        $this->abortIfUnconfigured();

        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

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
     * Streaming variant of interact() — re-emits Voiceflow's SSE frames to the
     * browser in real time. After the upstream stream closes, falls through to
     * the same post-processing (debit credits, record agent messages, broadcast,
     * capture lead) as the non-streaming path.
     */
    public function interactStream(Request $request, StreamingClient $streaming): StreamedResponse
    {
        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeInteractStream($request, $agent);
        }

        $this->abortIfUnconfigured();

        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            // Cannot return JSON from a stream endpoint; surface as SSE error event.
            return $this->sseError($credits->getData(true)['error'] ?? 'No credits', 402);
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);
        $conversation = $this->safelyResolve($request, $data['user_id'], $lead?->id);
        if ($conversation) {
            $this->safelyRecord($conversation, 'user', $data['message']);
        }
        $this->broadcastMessage($request, $lead, 'user', $data['message']);

        $userId = $data['user_id'];
        $message = $data['message'];

        // Capture team primitives BEFORE the closure — PHPStan can't infer through
        // request()->user() inside the StreamedResponse callback.
        $team = $request->user()->currentTeam;
        $currentAgentId = $team->current_agent_id ?? null;

        return new StreamedResponse(function () use ($streaming, $userId, $message, $request, $lead, $conversation, $team, $currentAgentId): void {
            $traces = [];

            try {
                foreach ($streaming->streamInteract($userId, ['type' => 'text', 'payload' => $message]) as $event) {
                    // Re-emit each event as SSE.
                    echo 'event: '.$event['event']."\n";
                    echo 'data: '.json_encode($event['data'])."\n\n";
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();

                    // Accumulate trace-style events for post-processing.
                    if ($event['event'] === 'trace') {
                        $traces[] = $event['data'];
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                echo 'event: error'."\n";
                echo 'data: '.json_encode(['error' => 'Upstream stream failed'])."\n\n";
                flush();

                return;
            }

            // Post-processing: parse, record agent messages, broadcast, debit credits,
            // capture lead. Mirrors the JsonResponse `respond()` flow but emits a
            // single "summary" frame instead of returning a JSON body.
            $parsed = $this->voiceflow->parseTraces($traces);
            $messagesBilled = 1 + count($parsed['messages']);

            if ($team instanceof Team) {
                try {
                    $this->credits->consume(
                        team: $team,
                        amount: $messagesBilled,
                        agentId: $currentAgentId,
                        meta: ['conversation_id' => $conversation?->id, 'user_id' => $userId, 'streaming' => true],
                    );
                } catch (OutOfCredits) {
                    // Post-call concurrency edge — user already paid implicitly via the
                    // stream. Log so ops sees the race; do not propagate (the stream
                    // already returned content to the client).
                    report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (streaming path)'));
                }
            }

            foreach ($parsed['messages'] as $text) {
                if ($text === '') {
                    continue;
                }
                if ($conversation) {
                    $this->safelyRecord($conversation, 'agent', $text);
                }
                $this->broadcastMessage($request, $lead, 'agent', $text);
            }

            // Optionally capture lead fields if the stream produced them.
            try {
                $variables = $this->voiceflow->getVariables($userId);
                $captured = $this->voiceflow->extractLeadFields($variables);
                if ($captured !== []) {
                    $lead = $this->upsertLead($request, $lead, $userId, $captured);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            echo 'event: summary'."\n";
            echo 'data: '.json_encode([
                'messages_billed' => $messagesBilled,
                'lead_id' => $lead?->id,
                'ended' => $parsed['ended'],
                'buttons' => $parsed['buttons'],
            ])."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    protected function sseError(string $message, int $status): StreamedResponse
    {
        return new StreamedResponse(function () use ($message, $status): void {
            echo 'event: error'."\n";
            echo 'data: '.json_encode(['error' => $message, 'status' => $status])."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Shared response builder: parse traces, broadcast agent messages, capture
     * lead fields from session variables, upsert the lead.
     */
    protected function respond(Request $request, string $userId, ?Lead $lead, array $traces, ?Conversation $conversation = null): JsonResponse
    {
        $parsed = $this->voiceflow->parseTraces($traces);

        $conversation ??= $this->safelyResolve($request, $userId, $lead?->id);

        // Debit credits: 1 for the user's incoming message + 1 per agent reply.
        // Done AFTER the Voiceflow call succeeds so users aren't charged for
        // failures. The pre-call ensureCredits() already proved there was
        // capacity; this consume() can still throw on a race condition with
        // a concurrent request, in which case the JSON error surfaces upstream.
        $team = $request->user()->currentTeam;
        $messagesBilled = 1 + count($parsed['messages']);
        try {
            $this->credits->consume(
                team: $team,
                amount: $messagesBilled,
                agentId: $team->current_agent_id,
                meta: ['conversation_id' => $conversation?->id, 'user_id' => $userId],
            );
        } catch (OutOfCredits) {
            // Edge case: post-call balance went negative due to concurrency.
            // We still want to return the assistant's reply (they paid for the
            // turn implicitly), but flag the empty wallet so the next turn fails.
            // Logged but not propagated — penalize the next interact() instead.
            report(new \RuntimeException('Credit debit raced past zero for team '.$team->id));
        }

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
            // Backfill agent_id on rows that pre-date multi-tenancy.
            if (! $lead->agent_id && $team->current_agent_id) {
                $lead->agent_id = $team->current_agent_id;
            }
            $lead->save();
            // Status transitions go through the lifecycle layer so typed
            // events fire. canTransitionTo guards against repeated calls
            // (idempotent — re-touching an Engaging lead is a no-op).
            if ($lead->canTransitionTo(LeadStatus::Engaging)) {
                $lead->transitionTo(LeadStatus::Engaging);
            }
        } else {
            $lead = Lead::create([
                ...$attributes,
                'team_id' => $team->id,
                'agent_id' => $team->current_agent_id,
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
            $team = $request->user()->currentTeam;

            return $this->recorder->resolve(
                teamId: $team->id,
                voiceflowUserId: $userId,
                leadId: $leadId,
                agentId: $team->current_agent_id,
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

    // ── Native runtime branch ───────────────────────────────────────────────

    /**
     * The current agent when it runs on the native engine, else null
     * (→ legacy Voiceflow path).
     */
    protected function nativeAgent(Request $request): ?Agent
    {
        $team = $request->user()?->currentTeam;
        if (! $team instanceof Team) {
            return null;
        }

        $agent = $team->currentAgent;

        return ($agent instanceof Agent && $agent->getAttribute('runtime_mode') === Agent::RUNTIME_NATIVE)
            ? $agent
            : null;
    }

    protected function nativeLaunch(Request $request, Agent $agent): JsonResponse
    {
        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'variables' => ['nullable', 'array'], // accepted for API parity; native pre-fill TBD
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);
        $userId = $lead?->voiceflow_user_id ?: 'web-'.Str::uuid()->toString();

        try {
            $traces = $this->runtime->launch($agent, $userId);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => 'The agent is temporarily unavailable.'], 503);
        }

        // Same continuity column as the legacy engine — it's just "the
        // external chat user id" regardless of which engine serves it.
        if ($lead && $lead->voiceflow_user_id !== $userId) {
            $lead->update(['voiceflow_user_id' => $userId]);
        }

        return $this->nativeRespond($request, $agent, $userId, $lead, $traces);
    }

    protected function nativeInteract(Request $request, Agent $agent): JsonResponse
    {
        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        $conversation = $this->safelyResolve($request, $data['user_id'], $lead?->id);
        if ($conversation) {
            $this->safelyRecord($conversation, 'user', $data['message']);
        }
        $this->broadcastMessage($request, $lead, 'user', $data['message']);

        try {
            $traces = $this->runtime->sendText($agent, $data['user_id'], $data['message']);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => 'The agent is temporarily unavailable.'], 503);
        }

        return $this->nativeRespond($request, $agent, $data['user_id'], $lead, $traces, $conversation);
    }

    /**
     * Streaming for native agents: stage-level events from the runtime
     * mapped onto the same SSE protocol the Chat page already speaks
     * (trace frames + a final summary frame).
     */
    protected function nativeInteractStream(Request $request, Agent $agent): StreamedResponse
    {
        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $this->sseError($credits->getData(true)['error'] ?? 'No credits', 402);
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);
        $conversation = $this->safelyResolve($request, $data['user_id'], $lead?->id);
        if ($conversation) {
            $this->safelyRecord($conversation, 'user', $data['message']);
        }
        $this->broadcastMessage($request, $lead, 'user', $data['message']);

        $team = $request->user()->currentTeam;

        return new StreamedResponse(function () use ($request, $agent, $data, $lead, $conversation, $team): void {
            $messages = [];
            $ended = false;

            try {
                foreach ($this->runtime->streamText($agent, $data['user_id'], $data['message']) as $event) {
                    if ($event['event'] === 'message') {
                        $text = (string) ($event['data']['message'] ?? '');
                        if ($text !== '') {
                            $messages[] = $text;
                        }
                        // Frame shape the Chat page renders: {type:'text', payload:{message}}.
                        echo 'event: trace'."\n";
                        echo 'data: '.json_encode(['type' => 'text', 'payload' => ['message' => $text]])."\n\n";
                    } elseif ($event['event'] === 'done') {
                        $ended = ($event['data']['state'] ?? '') === 'ended';
                    }
                    // 'tool' events are internal; the page ignores unknown frames anyway.
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                report($e);
                echo 'event: error'."\n";
                echo 'data: '.json_encode(['error' => 'The agent is temporarily unavailable.'])."\n\n";
                flush();

                return;
            }

            $messagesBilled = 1 + count($messages);
            if ($team instanceof Team) {
                try {
                    $this->credits->consume(
                        team: $team,
                        amount: $messagesBilled,
                        agentId: $agent->id,
                        meta: ['conversation_id' => $conversation?->id, 'user_id' => $data['user_id'], 'streaming' => true, 'engine' => 'native'],
                    );
                } catch (OutOfCredits) {
                    report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (native streaming path)'));
                }
            }

            foreach ($messages as $text) {
                if ($conversation) {
                    $this->safelyRecord($conversation, 'agent', $text);
                }
                $this->broadcastMessage($request, $lead, 'agent', $text);
            }

            if ($conversation && $ended) {
                try {
                    $this->recorder->end($conversation);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            echo 'event: summary'."\n";
            echo 'data: '.json_encode([
                'messages_billed' => $messagesBilled,
                'lead_id' => $lead?->id,
                'ended' => $ended,
                'buttons' => [],
            ])."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Native sibling of respond(): bill, record, broadcast, detect end —
     * but NO Voiceflow variable extraction (the capture_lead tool inside
     * the engine already wrote the lead + broadcast LeadSaved).
     *
     * @param  list<array<string, mixed>>  $traces
     */
    protected function nativeRespond(Request $request, Agent $agent, string $userId, ?Lead $lead, array $traces, ?Conversation $conversation = null): JsonResponse
    {
        $messages = [];
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text !== '') {
                $messages[] = $text;
            }
        }

        $conversation ??= $this->safelyResolve($request, $userId, $lead?->id);

        $team = $request->user()->currentTeam;
        $messagesBilled = 1 + count($messages);
        if ($team instanceof Team) {
            try {
                $this->credits->consume(
                    team: $team,
                    amount: $messagesBilled,
                    agentId: $agent->id,
                    meta: ['conversation_id' => $conversation?->id, 'user_id' => $userId, 'engine' => 'native'],
                );
            } catch (OutOfCredits) {
                report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (native)'));
            }
        }

        foreach ($messages as $message) {
            if ($conversation) {
                $this->safelyRecord($conversation, 'agent', $message);
            }
            $this->broadcastMessage($request, $lead, 'agent', $message);
        }

        // End detection: the runtime owns flow_state; 'ended' is terminal.
        $ended = RuntimeSession::query()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $userId)
            ->value('flow_state') === 'ended';

        if ($conversation && $ended) {
            try {
                $this->recorder->end($conversation);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'conversation_id' => $conversation?->id,
            'user_id' => $userId,
            'lead_id' => $lead?->id,
            'messages' => $messages,
            'buttons' => [],
            'ended' => $ended,
            'captured' => [], // native lead capture happens via the capture_lead tool
        ]);
    }

    /**
     * Quick pre-flight credit check. Returns a JsonResponse on failure
     * (mapped to HTTP 402 Payment Required so the chat UI can render an
     * "Out of credits" state). Returns null on success.
     *
     * We pre-check before the Voiceflow round-trip so users don't watch
     * a request hang then fail at the meter. The actual debit happens
     * inside respond() after Voiceflow returns successfully.
     */
    protected function ensureCredits(Request $request): ?JsonResponse
    {
        $team = $request->user()->currentTeam;

        if ($team->hasCredits()) {
            return null;
        }

        return response()->json([
            'error' => 'Out of credits for this billing period.',
            'plan' => $team->planObject()->value,
            'plan_label' => $team->planObject()->label(),
            'allows_topups' => $team->planObject()->allowsTopUps(),
        ], 402);
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
