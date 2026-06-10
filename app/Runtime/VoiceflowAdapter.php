<?php

namespace App\Runtime;

use App\Models\Agent;
use App\Providers\VoiceflowServiceProvider;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Services\Voiceflow\Exceptions\VoiceflowException;
use App\Services\VoiceflowService;
use Generator;
use Illuminate\Http\Client\RequestException;
use RuntimeException as BaseRuntimeException;
use Throwable;

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
        try {
            return $this->voiceflow->launch($visitorId);
        } catch (Throwable $e) {
            throw $this->normalize($e, 'launch');
        }
    }

    public function sendText(Agent $agent, string $visitorId, string $text): array
    {
        try {
            return $this->voiceflow->sendText($visitorId, $text);
        } catch (Throwable $e) {
            throw $this->normalize($e, 'sendText');
        }
    }

    public function streamText(Agent $agent, string $visitorId, string $text): Generator
    {
        $streaming = VoiceflowServiceProvider::streamingFor($agent);
        try {
            yield from $streaming->streamInteract($visitorId, ['type' => 'text', 'payload' => $text]);
        } catch (Throwable $e) {
            throw $this->normalize($e, 'streamText');
        }
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

    /**
     * Convert Voiceflow-side errors into the Runtime contract's umbrella
     * exception so callers catch one type (RuntimeException).
     *
     * VoiceflowService's public path actually throws RequestException +
     * RuntimeException via Http::throw(); the Voiceflow sub-clients
     * (StreamingClient/RuntimeClient) throw VoiceflowException subclasses.
     * Any of those normalize to UpstreamUnavailable here.
     */
    protected function normalize(Throwable $e, string $endpoint): UpstreamUnavailable
    {
        if ($e instanceof VoiceflowException || $e instanceof RequestException || $e instanceof BaseRuntimeException) {
            return new UpstreamUnavailable("Voiceflow {$endpoint} failed: ".$e->getMessage(), 0, $e);
        }

        return new UpstreamUnavailable("Voiceflow {$endpoint} unexpected error: ".$e->getMessage(), 0, $e);
    }
}
