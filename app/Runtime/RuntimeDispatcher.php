<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Runtime\Contracts\Runtime;
use App\Services\VoiceflowService;
use Generator;

/**
 * Routes runtime calls to the correct engine based on
 * agents.runtime_mode. Controllers and the embed flow depend on this
 * facade instead of a concrete VoiceflowService, so flipping an
 * agent to 'native' is just a column update.
 *
 * Phase 1 lands the dispatcher with one implemented backend
 * (Voiceflow, via the existing service) and the native runtime stub.
 * Phase 8 will swap controllers + EmbedController to depend on
 * Runtime (the interface) — at that point the dispatcher takes over
 * all conversational traffic without any direct VoiceflowService
 * imports remaining in the request path.
 */
class RuntimeDispatcher implements Runtime
{
    public function __construct(
        protected AgentRuntime $native,
    ) {}

    public function launch(Agent $agent, string $visitorId): array
    {
        return $this->engineFor($agent)->launch($agent, $visitorId);
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        return $this->engineFor($agent)->sendText($agent, $visitorId, $text);
    }

    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        yield from $this->engineFor($agent)->streamText($agent, $visitorId, $text);
    }

    public function endSession(Agent $agent, string $visitorId): void
    {
        $this->engineFor($agent)->endSession($agent, $visitorId);
    }

    public function health(Agent $agent): array
    {
        return $this->engineFor($agent)->health($agent);
    }

    /**
     * Resolve the runtime that should handle this agent based on its
     * runtime_mode column. Defaults to Voiceflow — only agents
     * explicitly flipped to 'native' touch the new code path.
     */
    protected function engineFor(Agent $agent): Runtime
    {
        $mode = (string) $agent->getAttribute('runtime_mode');

        return match ($mode) {
            'native' => $this->native,
            default => new VoiceflowAdapter(VoiceflowService::forAgent($agent)),
        };
    }
}
