<?php

namespace App\Support\Slack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Slack Incoming-Webhook poster — the single outbound-to-Slack surface.
 *
 * "Fully local" by design: no SDK, no bot OAuth token, no Slack Web API. The
 * only network call is the POST to the configured Incoming-Webhook URL (which
 * is unavoidable — that is how Slack receives a message). Everything else
 * (finding collection, dedupe, digest assembly, scheduling) runs in-process.
 *
 * No-ops cleanly when SLACK_ALERT_WEBHOOK_URL is unset, so local dev / CI stay
 * silent without any guarding at every call site. Reads config('services.slack.webhook_url').
 */
class SlackWebhook
{
    public function __construct(
        private readonly ?string $url = null,
    ) {}

    /** True when a webhook URL is configured and posts will actually go out. */
    public function enabled(): bool
    {
        return $this->resolveUrl() !== '';
    }

    /**
     * Post a message. $blocks is optional Slack Block Kit; $text is the
     * required fallback/notification string. Returns true when delivered.
     *
     * @param  list<array<string,mixed>>  $blocks
     */
    public function send(string $text, array $blocks = []): bool
    {
        $url = $this->resolveUrl();
        if ($url === '') {
            return false;
        }

        $payload = ['text' => $text];
        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        try {
            $response = Http::asJson()->timeout(5)->retry(2, 200)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('SlackWebhook: POST failed — '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::warning('SlackWebhook: Slack returned '.$response->status().' — '.$response->body());

            return false;
        }

        return true;
    }

    private function resolveUrl(): string
    {
        return trim((string) ($this->url ?? config('services.slack.webhook_url') ?? ''));
    }
}
