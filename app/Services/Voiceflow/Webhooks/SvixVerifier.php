<?php

namespace App\Services\Voiceflow\Webhooks;

/**
 * Verifies a Svix-signed webhook without taking the svix/svix composer dep.
 *
 * Spec (Svix Standard Webhooks):
 *   - Header `svix-id`: unique message ID
 *   - Header `svix-timestamp`: unix epoch seconds
 *   - Header `svix-signature`: space-separated list of `v1,<base64-hmac-sha256>`
 *     (a single message may carry multiple signatures during key rotation)
 *
 * Verification:
 *   1. Reject if the timestamp is older than 5 minutes (replay defence) or
 *      from > 5 minutes in the future (clock skew).
 *   2. Compute HMAC-SHA256 of `{svix-id}.{svix-timestamp}.{body}` with the
 *      base64-decoded secret (Svix secrets are `whsec_` + base64(key)).
 *   3. Constant-time compare the base64-encoded HMAC against each signature
 *      in the header. Any match → accept.
 *
 * @see https://github.com/svix/svix-webhooks/blob/main/specification/spec.md
 */
class SvixVerifier
{
    public const TOLERANCE_SECONDS = 5 * 60;

    /**
     * @param  string  $secret  Svix secret in `whsec_<base64-key>` form.
     */
    public function verify(string $secret, string $msgId, string $timestamp, string $signatureHeader, string $body, ?int $now = null): bool
    {
        if ($msgId === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;
        if ($ts <= 0) {
            return false;
        }

        $now ??= time();
        if (abs($now - $ts) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = $this->decodeSecret($secret);
        if ($key === null) {
            return false;
        }

        $payload = "{$msgId}.{$timestamp}.{$body}";
        $expected = base64_encode(hash_hmac('sha256', $payload, $key, true));

        // Header may contain multiple `v1,<sig>` entries separated by spaces.
        foreach (explode(' ', $signatureHeader) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || ! str_starts_with($piece, 'v1,')) {
                continue;
            }
            $candidate = substr($piece, 3);
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Svix secrets are stored as `whsec_<base64-of-raw-key>`. Strip the prefix
     * and base64-decode. Returns null on malformed inputs.
     */
    protected function decodeSecret(string $secret): ?string
    {
        if ($secret === '' || ! str_starts_with($secret, 'whsec_')) {
            return null;
        }

        $b64 = substr($secret, 6);
        $decoded = base64_decode($b64, strict: true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
