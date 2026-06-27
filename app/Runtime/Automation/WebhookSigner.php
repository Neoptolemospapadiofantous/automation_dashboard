<?php

namespace App\Runtime\Automation;

/**
 * HMAC-SHA256 signer for outbound automation webhooks. n8n verifies the
 * signature with the same per-agent secret before running the workflow, so a
 * leaked webhook URL alone can't trigger it.
 *
 * The signature is computed over `{timestamp}.{rawBody}` — binding the
 * timestamp into the signed material lets the receiver reject replays outside
 * a short window. Headers emitted:
 *   X-Flowstack-Timestamp  — unix seconds, also part of the signed payload
 *   X-Flowstack-Signature  — "sha256=" + hex HMAC
 *
 * Comparison on the receiver side must be constant-time (hash_equals).
 */
class WebhookSigner
{
    public const TIMESTAMP_HEADER = 'X-Flowstack-Timestamp';

    public const SIGNATURE_HEADER = 'X-Flowstack-Signature';

    /**
     * Signature headers for a request body. $timestamp is injectable so tests
     * are deterministic; production passes none and gets now().
     *
     * @return array{X-Flowstack-Timestamp: string, X-Flowstack-Signature: string}
     */
    public function headers(string $secret, string $rawBody, ?int $timestamp = null): array
    {
        $ts = (string) ($timestamp ?? time());
        $signature = $this->sign($secret, $ts, $rawBody);

        return [
            self::TIMESTAMP_HEADER => $ts,
            self::SIGNATURE_HEADER => 'sha256='.$signature,
        ];
    }

    /**
     * Raw hex HMAC over the timestamped body. Exposed for the receiver-side
     * verification tests (and any in-app inbound verifier we add later).
     */
    public function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
