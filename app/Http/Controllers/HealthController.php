<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated, cheap liveness/readiness probe for uptime monitoring
 * (PROJECT_ASSURANCE_STRATEGY.md G5). Checks the two dependencies every
 * request path needs — database and cache — and nothing else: no LLM or
 * provider calls, no queue work, no per-team data. 200 when everything is
 * up, 503 naming the failing component when degraded.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $ok = ! in_array('fail', $checks, true);

        return response()->json(
            ['ok' => $ok, 'checks' => $checks],
            $ok ? 200 : 503,
        );
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (\Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    private function checkCache(): string
    {
        try {
            /** @var string $key */
            $key = config('sla.health.cache_key', 'health:check');
            $value = (string) now()->timestamp;
            Cache::put($key, $value, 10);

            return Cache::get($key) === $value ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
