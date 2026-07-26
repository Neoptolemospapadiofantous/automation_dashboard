<?php

namespace Tests\Unit\Support;

use App\Support\PublicWebPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PublicWebPageTest extends TestCase
{
    public function test_rejects_non_http_schemes(): void
    {
        $this->expectException(RuntimeException::class);
        (new PublicWebPage)->assertPublicHttpUrl('file:///etc/passwd');
    }

    public function test_rejects_non_standard_ports(): void
    {
        // Internal services on odd ports (SSH, Redis, admin panels) must not be
        // reachable even when the host itself resolves publicly.
        $this->expectException(RuntimeException::class);
        (new PublicWebPage)->assertPublicHttpUrl('http://example.com:22/');
    }

    public function test_rejects_private_and_loopback_addresses(): void
    {
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'http://10.0.0.1/', 'http://192.168.1.1/'] as $url) {
            try {
                (new PublicWebPage)->assertPublicHttpUrl($url);
                $this->fail("Expected {$url} to be rejected as non-public.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_allows_a_public_url_on_the_standard_port(): void
    {
        // 8.8.8.8 is a public literal — validates without a DNS lookup and
        // returns the pinned IP list the fetch will connect to.
        $ips = (new PublicWebPage)->assertPublicHttpUrl('https://8.8.8.8/');
        $this->assertSame(['8.8.8.8'], $ips);
    }
}
