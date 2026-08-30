<?php

namespace App\Support\Findings;

use App\Models\AgentFinding;
use Illuminate\Support\Carbon;

/**
 * Persists collector runs and serves the latest per collector.
 *
 * The payload is the collector's findings.json body as-is (§3.1 shapes are
 * per collector and carry no schema field — nothing here interprets them),
 * so a reader that mirrors it back to disk reproduces the same document.
 */
class FindingsStore
{
    /** Collectors this store accepts — the dashboard-owned half of §3.1. */
    public const COLLECTORS = ['provider-health', 'system-check', 'audit-sentinel', 'update-inspector'];

    /**
     * Record one run. Idempotent on (collector, ts): re-ingesting the same
     * file is a no-op. Returns false when nothing was written.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $collector, array $payload): bool
    {
        $ts = isset($payload['ts']) && is_string($payload['ts']) ? Carbon::parse($payload['ts']) : now();

        if (AgentFinding::where('collector', $collector)->where('ts', $ts)->exists()) {
            return false;
        }

        AgentFinding::create([
            'collector' => $collector,
            'ts' => $ts,
            'overall' => (string) ($payload['overall'] ?? 'WARN'),
            'payload' => $payload,
        ]);

        return true;
    }

    /**
     * Latest run per collector.
     *
     * @return array<string, array{ts: string, overall: string, payload: array<string, mixed>}>
     */
    public function latest(): array
    {
        $out = [];
        foreach (self::COLLECTORS as $collector) {
            $row = AgentFinding::where('collector', $collector)->orderByDesc('ts')->first();
            if ($row instanceof AgentFinding) {
                $out[$collector] = [
                    'ts' => $row->ts->toIso8601ZuluString(),
                    'overall' => $row->overall,
                    'payload' => $row->payload,
                ];
            }
        }

        return $out;
    }

    /** Keep a bounded history: everything newer than $days, never fewer than $keep rows per collector. */
    public function prune(int $days = 30, int $keep = 50): int
    {
        $deleted = 0;
        foreach (self::COLLECTORS as $collector) {
            $floor = AgentFinding::where('collector', $collector)->orderByDesc('ts')->skip($keep)->value('ts');
            if ($floor === null) {
                continue;
            }
            $deleted += AgentFinding::where('collector', $collector)
                ->where('ts', '<', now()->subDays($days))
                ->where('ts', '<=', $floor)
                ->delete();
        }

        return $deleted;
    }
}
