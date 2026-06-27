<?php

namespace Tests\Unit\Runtime\Automation;

use App\Runtime\Automation\BlockedAutomationUrl;
use App\Runtime\Automation\OutboundGuard;
use Tests\TestCase;

/**
 * SSRF guard contract. The resolver is stubbed so these are pure, offline,
 * deterministic checks — no real DNS. Production-mode (allow_private=false)
 * is asserted explicitly because that's the configuration that actually ships.
 */
class OutboundGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Default to production posture; individual tests override.
        config(['runtime.automation.allow_private_hosts' => false]);
    }

    private function guard(string $resolvesTo): OutboundGuard
    {
        return new OutboundGuard(fn (string $host): array => [$resolvesTo]);
    }

    public function test_allows_public_https_host(): void
    {
        $result = $this->guard('93.184.216.34')->assertSafe('https://n8n.flowstack.run/webhook/abc');

        $this->assertSame('https', $result['scheme']);
        $this->assertSame('n8n.flowstack.run', $result['host']);
    }

    public function test_rejects_http_in_production(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('93.184.216.34')->assertSafe('http://n8n.flowstack.run/webhook/abc');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('93.184.216.34')->assertSafe('file:///etc/passwd');
    }

    public function test_rejects_embedded_credentials(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('93.184.216.34')->assertSafe('https://user:pass@n8n.flowstack.run/webhook');
    }

    public function test_rejects_host_resolving_to_loopback(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('127.0.0.1')->assertSafe('https://evil.example.com/webhook');
    }

    public function test_rejects_host_resolving_to_private_range(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('10.0.0.5')->assertSafe('https://internal.example.com/webhook');
    }

    public function test_rejects_cloud_metadata_endpoint(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        // 169.254.169.254 is link-local — the classic SSRF credential target.
        $this->guard('169.254.169.254')->assertSafe('https://metadata.example.com/');
    }

    public function test_rejects_literal_private_ip_host(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        $this->guard('203.0.113.1')->assertSafe('https://192.168.1.10/webhook');
    }

    public function test_rejects_unresolvable_host(): void
    {
        $this->expectException(BlockedAutomationUrl::class);
        (new OutboundGuard(fn (string $host): array => []))
            ->assertSafe('https://nope.invalid/webhook');
    }

    public function test_allows_localhost_when_private_hosts_enabled(): void
    {
        config(['runtime.automation.allow_private_hosts' => true]);

        $result = (new OutboundGuard(fn (string $host): array => ['127.0.0.1']))
            ->assertSafe('http://localhost:5678/webhook/abc');

        $this->assertSame('http', $result['scheme']);
        $this->assertSame('localhost', $result['host']);
    }

    public function test_is_public_ip_classification(): void
    {
        $guard = new OutboundGuard(fn () => []);

        $this->assertTrue($guard->isPublicIp('8.8.8.8'));
        $this->assertTrue($guard->isPublicIp('93.184.216.34'));

        $this->assertFalse($guard->isPublicIp('127.0.0.1'));
        $this->assertFalse($guard->isPublicIp('10.1.2.3'));
        $this->assertFalse($guard->isPublicIp('192.168.0.1'));
        $this->assertFalse($guard->isPublicIp('172.16.5.4'));
        $this->assertFalse($guard->isPublicIp('169.254.169.254'));
        $this->assertFalse($guard->isPublicIp('::1'));
    }
}
