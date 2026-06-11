<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Events\LeadMessage;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Models\RuntimeSession;
use App\Services\ConversationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side chat proxy for the dashboard's Chat page.
 *
 * The browser never talks to the conversational engine directly — it calls
 * these endpoints, the server advances the conversation through the native
 * runtime, records the transcript, debits credits, and broadcasts updates
 * to the team. Lead capture happens INSIDE the engine via the capture_lead
 * tool, so there is no variable extraction here.
 */
class ChatController extends Controller
{
    public function __construct(
        protected ConversationRecorder $recorder,
        protected CreditMeter $credits,
        protected Runtime $runtime,
    ) {}

    /**
     * Diagnostic: is the engine configured + which models will answer.
     * Safe — exposes no secrets.
     */
    public function health(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);
        if ($agent === null) {
            return response()->json(['ok' => false, 'configured' => false, 'reason' => 'No current agent.']);
        }

        return response()->json($this->runtime->health($agent));
    }

    /**
     * Start (or reset) a conversation. Returns a fresh user id + the opening
     * messages. Optionally links an existing lead for continuity.
     */
    public function launch(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);
        abort_if($agent === null, 503, 'No agent is set up yet.');

        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'variables' => ['nullable', 'array'], // accepted for API parity; pre-fill TBD
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);
        $userId = $lead?->voiceflow_user_id ?: 'web-'.Str::uuid()->toString();

        try {
            $traces = $this->runtime->launch($agent, $userId);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => 'The agent is temporarily unavailable.'], 503);
        }

        // Continuity: the lead's external chat id (column name is historical).
        if ($lead && $lead->voiceflow_user_id !== $userId) {
            $lead->update(['voiceflow_user_id' => $userId]);
        }

        return $this->respond($request, $agent, $userId, $lead, $traces);
    }

    /**
     * Send a user's text reply and advance the conversation.
     */
    public function interact(Request $request): JsonResponse
    {
        $agent = $this->currentAgent($request);
        abort_if($agent === null, 503, 'No agent is set up yet.');

        if (($credits = $this->ensureCredits($request)) instanceof JsonResponse) {
            return $credits;
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        // Persist + echo the user's message live before the engine call.
        // Recording is best-effort: a storage hiccup must never break chat.
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

        return $this->respond($request, $agent, $data['user_id'], $lead, $traces, $conversation);
    }

    /**
     * Streaming variant: stage-level events from the runtime mapped onto
     * the SSE protocol the Chat page speaks (trace frames + a summary frame).
     */
    public function interactStream(Request $request): StreamedResponse
    {
        $agent = $this->currentAgent($request);
        if ($agent === null) {
            return $this->sseError('No agent is set up yet.', 503);
        }

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
                        echo 'event: trace'."\n";
                        echo 'data: '.json_encode(['type' => 'text', 'payload' => ['message' => $text]])."\n\n";
                    } elseif ($event['event'] === 'done') {
                        $ended = ($event['data']['state'] ?? '') === 'ended';
                    }
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
                        meta: ['conversation_id' => $conversation?->id, 'user_id' => $data['user_id'], 'streaming' => true],
                    );
                } catch (OutOfCredits) {
                    report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (streaming path)'));
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

    // ── shared internals ─────────────────────────────────────────────────────

    /**
     * Bill, record, broadcast, detect end, return the page's JSON shape.
     *
     * @param  list<array<string, mixed>>  $traces
     */
    protected function respond(Request $request, Agent $agent, string $userId, ?Lead $lead, array $traces, ?Conversation $conversation = null): JsonResponse
    {
        $messages = [];
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text !== '') {
                $messages[] = $text;
            }
        }

        $conversation ??= $this->safelyResolve($request, $userId, $lead?->id);

        $team = $this->team($request);
        $messagesBilled = 1 + count($messages);
        try {
            $this->credits->consume(
                team: $team,
                amount: $messagesBilled,
                agentId: $agent->id,
                meta: ['conversation_id' => $conversation?->id, 'user_id' => $userId],
            );
        } catch (OutOfCredits) {
            // Post-call concurrency edge — the reply already happened.
            // Flag for ops; the NEXT turn fails the pre-check instead.
            report(new \RuntimeException('Credit debit raced past zero for team '.$team->id));
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
            'captured' => [], // lead capture happens via the capture_lead tool
        ]);
    }

    protected function team(Request $request): Team
    {
        $team = $request->user()?->currentTeam;
        abort_unless($team instanceof Team, 403);

        return $team;
    }

    protected function currentAgent(Request $request): ?Agent
    {
        $team = $request->user()?->currentTeam;
        if (! $team instanceof Team) {
            return null;
        }

        $agent = $team->currentAgent;

        return $agent instanceof Agent ? $agent : null;
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

    protected function broadcastMessage(Request $request, ?Lead $lead, string $role, string $text): void
    {
        broadcast(new LeadMessage(
            teamId: $this->team($request)->id,
            leadId: $lead?->id,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
        ));
    }

    /**
     * Resolve (find-or-create) the conversation without ever breaking chat
     * if storage fails. Returns null on failure (logged).
     */
    protected function safelyResolve(Request $request, string $userId, ?int $leadId): ?Conversation
    {
        try {
            $team = $this->team($request);

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

    protected function safelyRecord(Conversation $conversation, string $role, string $text): void
    {
        try {
            $this->recorder->record($conversation, $role, $text, 'text');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function resolveLead(Request $request, ?int $leadId): ?Lead
    {
        if (! $leadId) {
            return null;
        }

        return Lead::where('team_id', $this->team($request)->id)
            ->find($leadId);
    }

    /**
     * Pre-flight credit check → 402 JSON the chat UI renders as an
     * "Out of credits" state. Null on success. The actual debit happens
     * after the engine returns so users aren't charged for failures.
     */
    protected function ensureCredits(Request $request): ?JsonResponse
    {
        $team = $this->team($request);

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
}
