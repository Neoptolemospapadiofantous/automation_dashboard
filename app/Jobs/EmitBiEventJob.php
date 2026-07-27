<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fire-and-forget delivery of one business event to the ecosystem BI ingestion
 * API (SHARED.md — ~/.config/ecosystem/bi, POST {url}/event). This is the LIVE
 * half of the BI pipeline; the batch ETL backfills aggregates regardless, so
 * this is best-effort: a delivery failure is swallowed and never pollutes
 * failed_jobs or affects the originating request. Off unless services.bi.url is
 * configured (see BiEmitter for the enabled gate).
 */
class EmitBiEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // No $tries/$timeout override: handle() swallows all failures (best-effort
    // telemetry), so the framework default of 1 attempt is correct and the
    // Http::timeout(5) below bounds the runtime — a longer job timeout is moot.

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(public array $event) {}

    public function handle(): void
    {
        $url = config('services.bi.url');
        if (! is_string($url) || $url === '') {
            return;
        }

        $token = config('services.bi.token');
        $headers = is_string($token) && $token !== '' ? ['X-BI-Token' => $token] : [];

        try {
            Http::timeout(5)
                ->withHeaders($headers)
                ->post(rtrim($url, '/').'/event', $this->event);
        } catch (Throwable) {
            // Best-effort telemetry — BI being unreachable must never fail here.
        }
    }
}
