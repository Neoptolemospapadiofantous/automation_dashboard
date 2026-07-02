<?php

namespace App\Runtime\Automation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Performs the actual outbound HTTP call to an automation's webhook. The only
 * place a request physically leaves the box, so every defense converges here:
 * SSRF guard first, HMAC signing second, then a hard-timeout POST.
 *
 * Returns a small result array (never the raw Response) and throws only the
 * three things the dispatcher distinguishes: BlockedAutomationUrl (guard),
 * AutomationTimeout (slow/unreachable), AutomationFailed (everything else).
 */
class AutomationCaller
{
    public function __construct(
        private readonly OutboundGuard $guard,
        private readonly WebhookSigner $signer,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  Validated tool arguments.
     * @return array{http_status: int, body: string, duration_ms: int}
     *
     * @throws BlockedAutomationUrl URL failed the SSRF guard — never sent.
     * @throws AutomationTimeout Endpoint too slow / unreachable.
     * @throws AutomationFailed Non-2xx response or transport error.
     */
    public function send(AutomationAction $action, array $arguments, string $secret): array
    {
        // SSRF guard BEFORE anything leaves the box.
        $vetted = $this->guard->assertSafe($action->url);

        $body = (string) json_encode([
            'action' => $action->name,
            'arguments' => (object) $arguments,
        ], JSON_UNESCAPED_SLASHES);

        $timeout = max(1, (int) config('runtime.automation.sync_timeout', 6));
        $maxBytes = max(1024, (int) config('runtime.automation.max_response_bytes', 16384));

        $started = hrtime(true);

        try {
            // Redirects are never followed: the SSRF guard vetted only the
            // original URL, so a 3xx to a private host would bypass it (and
            // re-send the signature headers to the new target). Pinning the
            // connection to the vetted addresses (CURLOPT_RESOLVE) stops curl
            // re-resolving the host — DNS can't rebind between check and send.
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Flowstack-Automations/1',
                ...$this->signer->headers($secret, $body),
            ])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => $vetted['pin']],
                ])
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post($action->url);
        } catch (ConnectionException $e) {
            // Connect/read timeout or DNS/transport failure.
            throw new AutomationTimeout($e->getMessage(), 0, $e);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        // failed() only covers 4xx/5xx — reject 3xx explicitly, a webhook
        // target never legitimately redirects.
        if ($response->redirect() || $response->failed()) {
            throw new AutomationFailed(
                "Endpoint returned HTTP {$response->status()}.",
                $response->status(),
            );
        }

        return [
            'http_status' => $response->status(),
            'body' => substr($response->body(), 0, $maxBytes),
            'duration_ms' => $durationMs,
        ];
    }
}
