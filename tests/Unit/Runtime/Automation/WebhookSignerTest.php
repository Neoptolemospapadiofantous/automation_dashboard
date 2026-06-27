<?php

namespace Tests\Unit\Runtime\Automation;

use App\Runtime\Automation\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * HMAC signing contract — the bytes n8n will verify against. Pure, no
 * framework. Locks the signed-material recipe (timestamp.body) and header
 * names so a refactor can't silently break every receiver.
 */
class WebhookSignerTest extends TestCase
{
    public function test_signature_is_hmac_sha256_over_timestamped_body(): void
    {
        $signer = new WebhookSigner;
        $body = '{"action":"lookup_order","arguments":{"id":7}}';

        $headers = $signer->headers('s3cr3t', $body, 1_700_000_000);

        $expected = hash_hmac('sha256', '1700000000.'.$body, 's3cr3t');

        $this->assertSame('1700000000', $headers[WebhookSigner::TIMESTAMP_HEADER]);
        $this->assertSame('sha256='.$expected, $headers[WebhookSigner::SIGNATURE_HEADER]);
    }

    public function test_receiver_can_reproduce_the_signature(): void
    {
        $signer = new WebhookSigner;
        $body = 'payload';
        $headers = $signer->headers('key', $body, 42);

        // What an n8n verification node would compute.
        $ts = $headers[WebhookSigner::TIMESTAMP_HEADER];
        $recomputed = 'sha256='.$signer->sign('key', $ts, $body);

        $this->assertTrue(hash_equals($headers[WebhookSigner::SIGNATURE_HEADER], $recomputed));
    }

    public function test_different_secret_yields_different_signature(): void
    {
        $signer = new WebhookSigner;

        $a = $signer->headers('key-a', 'body', 1);
        $b = $signer->headers('key-b', 'body', 1);

        $this->assertNotSame(
            $a[WebhookSigner::SIGNATURE_HEADER],
            $b[WebhookSigner::SIGNATURE_HEADER],
        );
    }

    public function test_defaults_timestamp_to_now(): void
    {
        $headers = (new WebhookSigner)->headers('key', 'body');

        $this->assertEqualsWithDelta(time(), (int) $headers[WebhookSigner::TIMESTAMP_HEADER], 5);
    }
}
