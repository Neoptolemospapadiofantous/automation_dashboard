<?php

namespace App\Services\Voiceflow\Client;

use App\Services\Voiceflow\Exceptions\AuthException;
use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use App\Services\Voiceflow\Exceptions\NotFoundException;
use App\Services\Voiceflow\Exceptions\RateLimitedException;
use App\Services\Voiceflow\Exceptions\UpstreamException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central PendingRequest factory for every Voiceflow call.
 *
 * Why this exists:
 *   - One place to set timeouts, retries, headers, base URL
 *   - One place to map upstream HTTP status to typed exceptions
 *   - One place to emit structured logs
 *
 * Subclients (RuntimeClient, AnalyticsClient, RealtimeClient, StreamingClient)
 * delegate here and never touch Http:: directly.
 */
class VoiceflowHttpClient
{
    /** Default per-request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 20;

    /** Default connection-establish timeout in seconds. */
    public const CONNECT_TIMEOUT = 5;

    /** Number of retries on ConnectionException (not on HTTP status). */
    public const RETRY_TIMES = 2;

    /** Delay between retries in milliseconds. */
    public const RETRY_SLEEP_MS = 200;

    /**
     * Build a PendingRequest pre-configured for a Voiceflow host.
     *
     * @param  non-empty-string  $baseUrl  The host root, e.g. https://general-runtime.voiceflow.com
     * @param  non-empty-string  $apiKey  The auth key to send in the `authorization` header
     * @param  int|null  $timeout  Override default request timeout
     * @param  array<string,string>  $extraHeaders  Additional headers to merge
     */
    public function client(
        string $baseUrl,
        string $apiKey,
        ?int $timeout = null,
        array $extraHeaders = [],
    ): PendingRequest {
        if ($apiKey === '') {
            throw new MisconfiguredException('Voiceflow API key is empty', endpoint: $baseUrl);
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withHeaders(['authorization' => $apiKey] + $extraHeaders)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout ?? self::DEFAULT_TIMEOUT)
            ->retry(
                self::RETRY_TIMES,
                self::RETRY_SLEEP_MS,
                when: fn (Throwable $e) => $e instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * Inspect a Response and either return it (2xx) or raise a typed Voiceflow exception.
     *
     * Maps:
     *   401, 403 → AuthException
     *   404      → NotFoundException
     *   429      → RateLimitedException (with Retry-After if present)
     *   5xx + non-2xx → UpstreamException
     *
     * @param  non-empty-string  $endpoint  Logical endpoint (for logging/exception context)
     * @param  array<string,mixed>  $context  Extra log fields
     */
    public function ensureOk(Response $response, string $endpoint, array $context = []): Response
    {
        $status = $response->status();

        $this->logCall($endpoint, $status, $context);

        if ($response->successful()) {
            return $response;
        }

        $message = sprintf(
            'Voiceflow %s returned HTTP %d: %s',
            $endpoint,
            $status,
            mb_substr((string) $response->body(), 0, 200)
        );

        throw match (true) {
            $status === 401, $status === 403 => new AuthException($message, $status, $endpoint, $context),
            $status === 404 => new NotFoundException($message, $status, $endpoint, $context),
            $status === 429 => new RateLimitedException(
                $message,
                $this->parseRetryAfter($response),
                $status,
                $endpoint,
                $context,
            ),
            default => new UpstreamException($message, $status, $endpoint, $context),
        };
    }

    /**
     * Emit a structured log entry for one upstream call. Never logs API keys
     * or full bodies — only metadata.
     *
     * @param  array<string,mixed>  $context
     */
    protected function logCall(string $endpoint, int $status, array $context): void
    {
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'debug',
        };

        Log::channel(config('services.voiceflow.log_channel', 'stack'))->{$level}(
            'voiceflow.http',
            [
                'endpoint' => $endpoint,
                'status' => $status,
            ] + $context,
        );
    }

    protected function parseRetryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        $seconds = (int) $header;

        return $seconds > 0 ? $seconds : null;
    }
}
