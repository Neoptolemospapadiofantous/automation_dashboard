<?php

namespace App\Jobs;

use App\Models\TeamWebhook;
use App\Runtime\Exceptions\WebhookDeliveryFailed;
use App\Support\PublicWebPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * POST one signed event body to one webhook endpoint.
 *
 *   X-Flowstack-Event:     lead.captured
 *   X-Flowstack-Delivery:  <ulid>
 *   X-Flowstack-Signature: t=<unix>,v1=<hex hmac-sha256 of "<t>.<body>">
 *
 * The receiver recomputes the HMAC with the secret shown once at creation.
 * Three attempts with growing backoff; every attempt records its outcome
 * on the webhook row, and MAX_FAILURES consecutive failures switch the
 * endpoint off rather than hammering a dead URL forever.
 *
 * The URL was SSRF-checked when it was saved; it is checked again here so
 * a DNS change after saving cannot turn a webhook into an internal probe.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before the second and third attempts.
     *
     * @var list<int>
     */
    // @phpstan-ignore shipmonk.deadProperty.neverRead (read by the queue worker, not app code)
    public array $backoff = [30, 300];

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public int $webhookId, public array $body) {}

    public function handle(PublicWebPage $guard): void
    {
        $hook = TeamWebhook::query()->find($this->webhookId);
        if ($hook === null || ! $hook->active) {
            return;
        }

        $json = (string) json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$json, $hook->secret);

        try {
            $guard->assertPublicHttpUrl($hook->url);

            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Flowstack-Webhooks/1.0',
                    'X-Flowstack-Event' => (string) ($this->body['event'] ?? ''),
                    'X-Flowstack-Delivery' => (string) ($this->body['id'] ?? ''),
                    'X-Flowstack-Signature' => "t={$timestamp},v1={$signature}",
                ])
                ->withBody($json, 'application/json')
                ->post($hook->url);

            $this->record($hook, $response->status(), $response->successful() ? null : 'HTTP '.$response->status());

            if (! $response->successful()) {
                $this->retryOrGiveUp('HTTP '.$response->status());
            }
        } catch (WebhookDeliveryFailed $e) {
            throw $e; // already recorded — let the queue apply $backoff
        } catch (\Throwable $e) {
            $this->record($hook, null, mb_substr($e->getMessage(), 0, 500));
            $this->retryOrGiveUp($e->getMessage());
        }
    }

    /**
     * Throwing is what makes the queue retry with $backoff; on the last
     * attempt the failure has already been recorded, so end quietly.
     */
    private function retryOrGiveUp(string $reason): void
    {
        if ($this->attempts() < $this->tries) {
            throw new WebhookDeliveryFailed($reason);
        }
    }

    private function record(TeamWebhook $hook, ?int $status, ?string $error): void
    {
        $failures = $error === null ? 0 : $hook->failure_count + 1;

        $hook->forceFill([
            'last_status' => $status,
            'last_error' => $error,
            'last_delivered_at' => now(),
            'failure_count' => $failures,
            // A dead endpoint stops being retried on every event; the
            // settings page says why and offers a re-enable.
            'active' => $failures < TeamWebhook::MAX_FAILURES,
        ])->save();
    }
}
