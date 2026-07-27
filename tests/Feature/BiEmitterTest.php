<?php

namespace Tests\Feature;

use App\Jobs\EmitBiEventJob;
use App\Support\BiEmitter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The BI event emitter: gated OFF by default (so prod, which can't reach the
 * localhost-only ingestion API, stays dormant), dispatches a fire-and-forget
 * job when enabled, and the job POSTs to {url}/event with the token header.
 */
class BiEmitterTest extends TestCase
{
    public function test_emit_is_a_noop_when_disabled(): void
    {
        Queue::fake();
        config(['services.bi.enabled' => false, 'services.bi.url' => 'http://127.0.0.1:8098']);

        BiEmitter::emit('dashboard', 'message_handled', ['value' => 1]);

        Queue::assertNothingPushed();
    }

    public function test_emit_is_a_noop_when_url_missing(): void
    {
        Queue::fake();
        config(['services.bi.enabled' => true, 'services.bi.url' => null]);

        BiEmitter::emit('dashboard', 'message_handled');

        Queue::assertNothingPushed();
    }

    public function test_emit_dispatches_the_job_with_the_event_when_enabled(): void
    {
        Queue::fake();
        config(['services.bi.enabled' => true, 'services.bi.url' => 'http://127.0.0.1:8098']);

        BiEmitter::emit('dashboard', 'lead_captured', [
            'customer' => 'team-13',
            'metric' => 'leads',
            'value' => 1,
        ]);

        Queue::assertPushed(EmitBiEventJob::class, function (EmitBiEventJob $job): bool {
            return $job->event['source'] === 'dashboard'
                && $job->event['type'] === 'lead_captured'
                && $job->event['customer'] === 'team-13'
                && $job->event['value'] === 1
                && isset($job->event['ts']);
        });
    }

    public function test_job_posts_the_event_to_the_ingestion_api_with_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config([
            'services.bi.url' => 'http://127.0.0.1:8098',
            'services.bi.token' => 'secret-token',
        ]);

        (new EmitBiEventJob(['source' => 'stripe', 'type' => 'subscription_created', 'value' => 399]))->handle();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8098/event'
                && $request->hasHeader('X-BI-Token', 'secret-token')
                && $request['type'] === 'subscription_created';
        });
    }

    public function test_job_failure_is_swallowed(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        config(['services.bi.url' => 'http://127.0.0.1:8098', 'services.bi.token' => 't']);

        // Best-effort telemetry: a dead ingestion API must not throw out of the job.
        (new EmitBiEventJob(['source' => 'dashboard', 'type' => 'message_handled']))->handle();

        $this->assertTrue(true);
    }
}
