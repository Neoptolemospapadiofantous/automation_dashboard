<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Providers\VoiceflowServiceProvider;
use App\Runtime\Contracts\Runtime;
use App\Services\VoiceflowService;
use Generator;

/**
 * Adapter so the legacy VoiceflowService satisfies the Runtime contract.
 * Translates the Runtime-shaped calls (launch / sendText / streamText /
 * endSession / health) into the equivalent VoiceflowService methods —
 * no behaviour change, just a uniform interface.
 *
 * Once every existing agent has been migrated to the native runtime,
 * this adapter and VoiceflowService can be deleted in one commit.
 */
class VoiceflowAdapter implements Runtime
{
    public function __construct(
        protected VoiceflowService $voiceflow,
    ) {}

    public function launch(Agent $agent, string $visitorId): array
    {
        return $this->voiceflow->launch($visitorId);
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        return $this->voiceflow->sendText($visitorId, $text);
    }

    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        $streaming = VoiceflowServiceProvider::streamingFor($agent);
        yield from $streaming->streamInteract($visitorId, ['type' => 'text', 'payload' => $text]);
    }

    public function endSession(Agent $agent, string $visitorId): void
    {
        // Voiceflow side stays stateful per session-key; the next launch()
        // resets it. We invalidate the local session cache by calling
        // launch fresh on the next interact. No explicit teardown call
        // needed against Voiceflow's API.
    }

    public function health(Agent $agent): array
    {
        $h = $this->voiceflow->health();
        $h['engine'] = 'voiceflow';

        return $h;
    }
}
