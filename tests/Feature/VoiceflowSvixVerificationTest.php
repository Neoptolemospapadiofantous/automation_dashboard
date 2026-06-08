<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Webhooks\SvixVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceflowSvixVerificationTest extends TestCase
{
    use RefreshDatabase;

    // 32 bytes of zero-padded "test-key", base64-encoded after the `whsec_` prefix.
    private const SECRET_KEY = 'test-key-bytes-32-long-padding-aa';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'whsec_'.base64_encode(self::SECRET_KEY);
        config()->set('services.voiceflow.svix_secret', $this->secret);
        config()->set('services.voiceflow.org_webhook_secret', null);
    }

    private function sign(string $msgId, int $ts, string $body): string
    {
        $payload = "{$msgId}.{$ts}.{$body}";
        $sig = base64_encode(hash_hmac('sha256', $payload, self::SECRET_KEY, true));

        return "v1,{$sig}";
    }

    public function test_verifier_accepts_valid_signature(): void
    {
        $verifier = new SvixVerifier;
        $ts = time();
        $body = json_encode(['type' => 'organization.project.deleted', 'data' => ['projectID' => 'p1']]);
        $sig = $this->sign('msg-1', $ts, $body);

        $this->assertTrue($verifier->verify($this->secret, 'msg-1', (string) $ts, $sig, $body));
    }

    public function test_verifier_rejects_tampered_body(): void
    {
        $verifier = new SvixVerifier;
        $ts = time();
        $body = '{"type":"X"}';
        $sig = $this->sign('msg-1', $ts, $body);

        // Same signature, different body → fail.
        $this->assertFalse($verifier->verify($this->secret, 'msg-1', (string) $ts, $sig, '{"type":"OTHER"}'));
    }

    public function test_verifier_rejects_stale_timestamp(): void
    {
        $verifier = new SvixVerifier;
        $now = 1_700_000_000;
        $stale = $now - (SvixVerifier::TOLERANCE_SECONDS + 1);
        $body = '{"a":1}';
        $sig = $this->sign('msg-1', $stale, $body);

        $this->assertFalse($verifier->verify($this->secret, 'msg-1', (string) $stale, $sig, $body, $now));
    }

    public function test_verifier_rejects_future_timestamp(): void
    {
        $verifier = new SvixVerifier;
        $now = 1_700_000_000;
        $future = $now + (SvixVerifier::TOLERANCE_SECONDS + 1);
        $body = '{}';
        $sig = $this->sign('msg-1', $future, $body);

        $this->assertFalse($verifier->verify($this->secret, 'msg-1', (string) $future, $sig, $body, $now));
    }

    public function test_verifier_accepts_one_of_multiple_signatures(): void
    {
        $verifier = new SvixVerifier;
        $ts = time();
        $body = '{}';
        $realSig = $this->sign('msg-1', $ts, $body);
        $combined = "v1,wrong-sig {$realSig}";

        $this->assertTrue($verifier->verify($this->secret, 'msg-1', (string) $ts, $combined, $body));
    }

    public function test_verifier_rejects_malformed_secret(): void
    {
        $verifier = new SvixVerifier;
        $ts = time();
        $body = '{}';

        $this->assertFalse($verifier->verify('not-whsec-prefixed', 'msg-1', (string) $ts, 'v1,x', $body));
        $this->assertFalse($verifier->verify('whsec_!!not-base64!!', 'msg-1', (string) $ts, 'v1,x', $body));
        $this->assertFalse($verifier->verify('', 'msg-1', (string) $ts, 'v1,x', $body));
    }

    public function test_controller_accepts_svix_signed_payload(): void
    {
        $ts = time();
        $body = json_encode([
            'type' => 'organization.project.created',
            'data' => ['projectID' => 'proj-new'],
            'time' => $ts * 1000,
        ]);
        $sig = $this->sign('msg-controller', $ts, $body);

        $this->call(
            'POST',
            route('voiceflow.webhook.org'),
            content: $body,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_svix-id' => 'msg-controller',
                'HTTP_svix-timestamp' => (string) $ts,
                'HTTP_svix-signature' => $sig,
            ],
        )->assertOk();
    }

    public function test_controller_rejects_bad_svix_signature(): void
    {
        $ts = time();
        $body = '{"type":"organization.project.created","data":{}}';

        $this->call(
            'POST',
            route('voiceflow.webhook.org'),
            content: $body,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_svix-id' => 'msg-bad',
                'HTTP_svix-timestamp' => (string) $ts,
                'HTTP_svix-signature' => 'v1,definitely-wrong',
            ],
        )->assertStatus(401);
    }
}
