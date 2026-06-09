<?php

namespace App\Events\Domain;

use App\Models\Agent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Result of a health probe — fired regardless of pass/fail so admins can
 * see why an agent is stuck in draft.
 */
class AgentHealthChecked
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $result  Raw payload from VoiceflowService::health()
     * @param  bool|null  $wasOk  Previous health state. Null means "unknown / first probe."
     *                            Set explicitly by the dispatcher (UpdateAgentCredentials,
     *                            etc.) BEFORE the agent is saved with the new state, so
     *                            transition detection works after the save commits.
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly bool $ok,
        public readonly array $result = [],
        public readonly ?bool $wasOk = null,
    ) {}
}
