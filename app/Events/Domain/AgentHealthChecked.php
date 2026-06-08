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
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly bool $ok,
        public readonly array $result = [],
    ) {}
}
