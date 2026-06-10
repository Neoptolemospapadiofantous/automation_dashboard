<?php

namespace App\Runtime;

use RuntimeException;

/**
 * Marker exception thrown by AgentRuntime stubs during the Phase 1-7
 * incremental build. If this lands in production it means we accidentally
 * flipped an agent to runtime_mode='native' before the relevant phase
 * shipped. Tests assert this is thrown so we know which phases are still
 * stubs.
 */
class RuntimeNotReady extends RuntimeException {}
