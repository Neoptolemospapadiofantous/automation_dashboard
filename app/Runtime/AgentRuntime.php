<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Flow\FlowExecutor;
use App\Runtime\Flow\LeadCaptureFlow;
use App\Runtime\Flow\TurnResult;
use App\Runtime\LLM\LlmRouter;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Session\SessionManager;
use Generator;

/**
 * Flowstack's native conversational engine.
 *
 * Composition: SessionManager owns per-visitor state, FlowExecutor runs
 * the LLM/tool loop against the agent's Flow (LeadCaptureFlow for every
 * agent today; per-agent flow selection is a column away when templates
 * land). Credits are charged by the CONTROLLERS around these calls —
 * this class knows nothing about billing.
 *
 * Bound to the Runtime contract in AppServiceProvider — the only engine.
 */
class AgentRuntime implements Runtime
{
    public function __construct(
        protected FlowExecutor $executor,
        protected SessionManager $sessions,
        protected LeadCaptureFlow $flow,
    ) {}

    /**
     * Reset-and-greet (the embed iframe calls launch on every open; the
     * greeting replays from a fresh session).
     */
    public function launch(Agent $agent, string $visitorId): array
    {
        $session = $this->sessions->reset($agent, $visitorId, $this->flow->initial());

        // Operator-authored greeting → serve it verbatim: zero tokens and an
        // instant open instead of a full LLM turn. History is seeded in the
        // exact shape execute() would have produced (synthetic user prompt +
        // assistant text blocks) so transcript replay, trimming, and the
        // next turn's context all behave identically.
        $greeting = trim((string) ((AgentConfigVersion::publishedConfig($agent->id) ?? [])['greeting'] ?? ''));
        if ($greeting !== '') {
            $initial = $this->flow->resolve($this->flow->initial());
            $session->flow_state = $initial->autoNext ?? $this->flow->initial();
            $this->sessions->appendHistory($session, [
                ['role' => 'user', 'content' => FlowExecutor::OPENING_MESSAGE],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $greeting]]],
            ]);

            return [['type' => 'text', 'payload' => ['message' => $greeting]]];
        }

        $result = $this->executor->execute(
            new ConversationContext($agent, $session, FlowExecutor::OPENING_MESSAGE),
            $this->flow,
        );

        return $result->traces;
    }

    public function hasSession(Agent $agent, string $visitorId): bool
    {
        $session = $this->sessions->find($agent, $visitorId);

        return $session !== null
            && $session->flow_state !== 'ended'
            && (array) ($session->history ?? []) !== [];
    }

    public function transcript(Agent $agent, string $visitorId): array
    {
        $session = $this->sessions->find($agent, $visitorId);
        if ($session === null) {
            return [];
        }

        $out = [];
        foreach ((array) ($session->history ?? []) as $entry) {
            $role = $entry['role'] ?? null;
            $content = $entry['content'] ?? null;

            if ($role === 'user' && is_string($content)) {
                if ($content === FlowExecutor::OPENING_MESSAGE) {
                    continue; // synthetic greeting prompt, not a real visitor message
                }
                $out[] = ['role' => 'user', 'text' => $content];
            } elseif ($role === 'assistant') {
                $text = $this->extractText($content);
                if (trim($text) !== '') {
                    $out[] = ['role' => 'agent', 'text' => $text];
                }
            }
            // tool_result entries (role=user, array content) are not displayed
        }

        return $out;
    }

    /**
     * Pull the plain text out of an LLM history entry's content (string or
     * Anthropic content blocks).
     */
    protected function extractText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        return $this->turn($agent, $visitorId, $text)->traces;
    }

    /**
     * Stage-level streaming: the turn runs to completion, then events are
     * yielded in order (tool events → message → done). Token-level SSE
     * pass-through from Anthropic is a planned refinement; the embed UI
     * consumes whole messages today so this shape is already sufficient.
     */
    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        $result = $this->turn($agent, $visitorId, $text);

        foreach ($result->toolEvents as $event) {
            yield ['event' => 'tool', 'data' => $event];
        }

        foreach ($result->traces as $trace) {
            yield ['event' => 'message', 'data' => $trace['payload'] ?? []];
        }

        yield ['event' => 'done', 'data' => ['state' => $result->finalState]];
    }

    public function endSession(Agent $agent, string $visitorId): void
    {
        $this->sessions->end($agent, $visitorId);
    }

    /**
     * Env var behind each provider's key — so a health failure names the
     * exact thing to set rather than a provider-agnostic "LLM key".
     */
    private const PROVIDER_KEY_ENV = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'google' => 'GEMINI_API_KEY',
    ];

    public function health(Agent $agent): array
    {
        // Check the provider THIS agent's published tier actually uses — not a
        // hard-coded Anthropic. A Gemini-tier agent is dead without a Gemini
        // key even when the Anthropic key is present (and vice versa).
        $tier = AgentConfigVersion::publishedTier($agent->id);
        $provider = (string) config("runtime.tiers.{$tier}.provider", 'anthropic');

        if (! LlmRouter::providerAvailable($provider)) {
            $env = self::PROVIDER_KEY_ENV[$provider] ?? strtoupper($provider).'_API_KEY';

            return [
                'ok' => false,
                'configured' => false,
                'tier' => $tier,
                'provider' => $provider,
                'reason' => "{$env} not set — this agent's {$tier} tier runs on {$provider}.",
            ];
        }

        if ((string) config('runtime.embeddings.openai_api_key') === '') {
            return [
                'ok' => false,
                'configured' => false,
                'tier' => $tier,
                'provider' => $provider,
                'reason' => 'OPENAI_API_KEY (for embeddings/RAG) not set.',
            ];
        }

        return [
            'ok' => true,
            'configured' => true,
            'engine' => 'native',
            'tier' => $tier,
            'provider' => $provider,
            'llm_model' => AgentConfigVersion::modelForTier($tier),
            'embedding_model' => (string) config('runtime.embeddings.model'),
        ];
    }

    protected function turn(Agent $agent, string $visitorId, string $text): TurnResult
    {
        $session = $this->sessions->findOrCreate($agent, $visitorId, $this->flow->initial());

        // Safety cap: a session can't run forever (cost protection).
        $maxTurns = max(1, (int) config('runtime.safety.max_turns_per_session'));
        if ($this->sessions->userTurnCount($session) >= $maxTurns) {
            return new TurnResult(
                traces: [['type' => 'text', 'payload' => [
                    'message' => 'This conversation has reached its limit — a teammate will pick it up from here. Thanks for your patience!',
                ]]],
                finalState: $session->flow_state,
                toolEvents: [],
            );
        }

        return $this->executor->execute(
            new ConversationContext($agent, $session, $text),
            $this->flow,
        );
    }
}
