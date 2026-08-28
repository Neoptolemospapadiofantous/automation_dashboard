<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Events\LeadMessage;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use App\Services\ConversationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/slack/agent-turn — the hermes-slack bot's LLM leg.
 *
 * hermes-slack (Socket Mode bot) forwards @mentions, DMs, and `/agent` here;
 * this side owns team/agent resolution and billing so the Slack repo never
 * touches the domain layer. Contract: hermes-slack INTEGRATION.md §2 /
 * ecosystem SHARED.md §3.1 — 200 `{reply}`, 402 out of credits, anything
 * else renders as "temporarily unavailable" in Slack.
 *
 * Local-team tool by design — Slack is the development team's surface, so
 * this endpoint refuses to serve in production (mirrors hermes-slack's
 * `slack:listen`, which likewise won't run in prod). The deployed app keeps
 * the route but always answers 503 there.
 *
 * Auth is one shared bearer token (SLACK_AGENT_TURN_TOKEN) mapping the whole
 * workspace to a single team (SLACK_AGENT_TURN_TEAM_ID) — coarse by design;
 * there is no per-Slack-user identity (SHARED.md §3.3). Session continuity
 * comes from the visitor id `slack:{user}:{channel}`.
 *
 * Billing matches the web chat basis: (1 per user message + 1 per agent
 * reply) × the agent's quality-tier multiplier, debited after the engine
 * replies so failed turns are never billed. No retry client-side — a timeout
 * must not double-bill.
 */
class SlackAgentTurnController extends Controller
{
    public function __construct(
        protected CreditMeter $credits,
        protected Runtime $runtime,
        protected ConversationRecorder $recorder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (app()->isProduction()) {
            return response()->json(['error' => 'Slack agent turns are local-only.'], 503);
        }

        $expected = (string) config('services.slack.agent_turn_token');
        if ($expected === '') {
            return response()->json(['error' => 'Slack agent turns are not configured.'], 503);
        }
        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'surface' => ['required', 'in:slack'],
            'user' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $team = Team::find((int) config('services.slack.agent_turn_team_id'));
        if (! $team instanceof Team) {
            return response()->json(['error' => 'Slack agent turns are not configured.'], 503);
        }

        $agent = $team->currentAgent;
        if (! $agent instanceof Agent || $agent->status !== Agent::STATUS_ACTIVE) {
            return response()->json(['error' => 'No active agent for this team.'], 503);
        }

        // Pre-check only — the debit happens AFTER the engine replies, same
        // basis as the web chat paths, so failures are never charged.
        $multiplier = AgentConfigVersion::creditsPerMessage($agent->id);
        if (! $team->hasCredits($multiplier)) {
            return response()->json(['error' => 'Out of credits.'], 402);
        }

        $visitorId = 'slack:'.$data['user'].':'.$data['channel'];
        $conversation = $this->recordConversation($agent, $visitorId);
        $this->recordMessage($conversation, 'user', $data['text']);
        $this->broadcastSlack($team->id, 'user', $data['text']);

        try {
            $traces = $this->runtime->sendText($agent, $visitorId, $data['text']);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => 'The agent is temporarily unavailable.'], 503);
        }

        $parts = [];
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $parts[] = $text;
            $this->recordMessage($conversation, 'agent', $text, (array) ($trace['payload']['citations'] ?? []));
            $this->broadcastSlack($team->id, 'agent', $text);
        }

        try {
            $this->credits->consume(
                team: $team,
                amount: (1 + count($parts)) * $multiplier,
                agentId: $agent->id,
                meta: ['slack' => true],
            );
        } catch (OutOfCredits) {
            // Post-reply race — the turn already happened; flag for ops and
            // let the NEXT turn fail the pre-check.
            report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (slack)'));
        }

        return response()->json(['reply' => implode("\n\n", $parts)]);
    }

    /**
     * Resolve (find-or-create) the operator-visible conversation for a Slack
     * user+channel. Best-effort — recording must never break the turn.
     */
    protected function recordConversation(Agent $agent, string $visitorId): ?Conversation
    {
        try {
            return $this->recorder->resolve(
                teamId: (int) $agent->team_id,
                visitorId: $visitorId,
                channel: 'slack',
                agentId: $agent->id,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    protected function recordMessage(?Conversation $conversation, string $role, string $text, array $citations = []): void
    {
        if ($conversation === null) {
            return;
        }
        try {
            $this->recorder->record($conversation, $role, $text, 'text', null, $citations ?: null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function broadcastSlack(int $teamId, string $role, string $text): void
    {
        rescue(fn () => broadcast(new LeadMessage(
            teamId: $teamId,
            leadId: null,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
        )), report: false);
    }
}
