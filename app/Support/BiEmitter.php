<?php

namespace App\Support;

use App\Jobs\EmitBiEventJob;
use Illuminate\Support\Carbon;

/**
 * Emits business events to the ecosystem BI warehouse's ingestion API — the
 * live half of the BI pipeline (SHARED.md — ~/.config/ecosystem/bi). Gated OFF
 * by default (services.bi.enabled): the ingestion API is localhost-only today,
 * so production — which cannot reach it — stays dormant until a reachable
 * endpoint (Azure) exists; enabling it is then one env flag, no code change.
 *
 * Never throws and never blocks: the actual HTTP POST is offloaded to a queued
 * EmitBiEventJob, and even the dispatch is wrapped so telemetry can't break a
 * customer request. When disabled or unconfigured it is a cheap no-op.
 */
class BiEmitter
{
    /**
     * @param  array<string, mixed>  $fields  optional: project, channel, customer, metric, value, payload
     */
    public static function emit(string $source, string $type, array $fields = []): void
    {
        if (! (bool) config('services.bi.enabled', false)) {
            return;
        }

        $url = config('services.bi.url');
        if (! is_string($url) || $url === '') {
            return;
        }

        rescue(
            fn () => EmitBiEventJob::dispatch(array_merge([
                'source' => $source,
                'type' => $type,
                'ts' => Carbon::now()->toIso8601String(),
            ], $fields)),
            report: false,
        );
    }
}
