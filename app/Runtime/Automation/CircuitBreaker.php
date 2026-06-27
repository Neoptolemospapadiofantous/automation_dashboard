<?php

namespace App\Runtime\Automation;

use Illuminate\Support\Facades\Cache;

/**
 * Per-agent-per-action circuit breaker. A flapping n8n endpoint shouldn't
 * burn credits and turn latency on every call — after a run of consecutive
 * failures the breaker OPENS and the dispatcher short-circuits (no billing,
 * no request) until a cooldown elapses.
 *
 * State lives in the cache (not the DB) — it's hot-path, ephemeral, and a
 * cleared cache simply means "give the endpoint another chance", which is a
 * safe default. Keyed per action so one broken automation never pauses the
 * agent's healthy ones.
 */
class CircuitBreaker
{
    private const FAILURE_TTL = 300; // window over which failures accumulate

    public function isOpen(int $agentId, string $action): bool
    {
        return Cache::get($this->openKey($agentId, $action)) !== null;
    }

    public function recordSuccess(int $agentId, string $action): void
    {
        Cache::forget($this->failKey($agentId, $action));
        Cache::forget($this->openKey($agentId, $action));
    }

    public function recordFailure(int $agentId, string $action): void
    {
        $threshold = max(1, (int) config('runtime.automation.breaker_threshold', 5));
        $cooldown = max(1, (int) config('runtime.automation.breaker_cooldown', 60));

        $failures = (int) Cache::get($this->failKey($agentId, $action), 0) + 1;
        Cache::put($this->failKey($agentId, $action), $failures, self::FAILURE_TTL);

        if ($failures >= $threshold) {
            Cache::put($this->openKey($agentId, $action), true, $cooldown);
        }
    }

    private function failKey(int $agentId, string $action): string
    {
        return "automation:breaker:fails:{$agentId}:{$action}";
    }

    private function openKey(int $agentId, string $action): string
    {
        return "automation:breaker:open:{$agentId}:{$action}";
    }
}
