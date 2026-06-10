<?php

namespace App\Runtime\Session;

use App\Models\Agent;
use App\Runtime\Models\RuntimeSession;

/**
 * Per-turn assembled context passed to every Tool::execute() call.
 *
 * Holds the immutable things a tool needs to do its job:
 *   - the Agent the conversation belongs to (for tenant scoping)
 *   - the RuntimeSession (for variables + flow state)
 *   - the visitor's user-facing message that triggered this turn
 *
 * Phase 1 lands the DTO so the Tool contract's signature compiles;
 * Phase 3 (Tools) wires the registry that builds and passes it.
 *
 * @api  Public DTO consumed by every Tool implementation; the readonly
 *       fields are read by tool authors (third-party + first-party).
 */
class ConversationContext
{
    public function __construct(
        public readonly Agent $agent,
        public readonly RuntimeSession $session,
        public readonly string $userMessage,
    ) {}
}
