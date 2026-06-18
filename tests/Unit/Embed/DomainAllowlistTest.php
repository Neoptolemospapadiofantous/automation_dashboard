<?php

namespace Tests\Unit\Embed;

use App\Support\Embed\DomainAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Pure matching logic for the embeddable-widget domain allowlist.
 * No database, no framework — exercise the static methods directly.
 */
class DomainAllowlistTest extends TestCase
{
    public function test_empty_allowlist_permits_any_host(): void
    {
        $this->assertTrue(DomainAllowlist::allows([], 'anything.example'));
        $this->assertTrue(DomainAllowlist::allows([], 'https://evil.com/path'));
        $this->assertTrue(DomainAllowlist::allows([], ''));
        $this->assertTrue(DomainAllowlist::allows([], null));
    }

    public function test_exact_pattern_matches_apex_only(): void
    {
        $patterns = ['acme.com'];

        $this->assertTrue(DomainAllowlist::allows($patterns, 'acme.com'));
        $this->assertTrue(DomainAllowlist::allows($patterns, 'https://acme.com/path'));

        $this->assertFalse(DomainAllowlist::allows($patterns, 'evil.com'));
        $this->assertFalse(DomainAllowlist::allows($patterns, 'notacme.com'));
        $this->assertFalse(DomainAllowlist::allows($patterns, 'app.acme.com'));
    }

    public function test_wildcard_pattern_matches_subdomains_and_apex(): void
    {
        $patterns = ['*.acme.com'];

        $this->assertTrue(DomainAllowlist::allows($patterns, 'app.acme.com'));
        $this->assertTrue(DomainAllowlist::allows($patterns, 'deep.nested.acme.com'));
        $this->assertTrue(DomainAllowlist::allows($patterns, 'acme.com')); // apex too

        // Suffix-spoof: must not match a host that merely ends with "acme.com"
        // as a label of another domain.
        $this->assertFalse(DomainAllowlist::allows($patterns, 'acme.com.evil.com'));
        $this->assertFalse(DomainAllowlist::allows($patterns, 'notacme.com'));
    }

    public function test_restricted_allowlist_denies_when_no_host(): void
    {
        $this->assertFalse(DomainAllowlist::allows(['acme.com'], ''));
        $this->assertFalse(DomainAllowlist::allows(['acme.com'], null));
    }

    public function test_host_extracts_from_urls_and_bare_hosts(): void
    {
        $this->assertSame('acme.com', DomainAllowlist::host('https://acme.com/path'));
        $this->assertSame('acme.com', DomainAllowlist::host('acme.com'));
        $this->assertSame('acme.com', DomainAllowlist::host('http://acme.com:443'));
        $this->assertSame('acme.com', DomainAllowlist::host('HTTPS://ACME.COM')); // lowercased
    }

    public function test_host_returns_null_for_empty(): void
    {
        $this->assertNull(DomainAllowlist::host(''));
        $this->assertNull(DomainAllowlist::host('   '));
        $this->assertNull(DomainAllowlist::host(null));
    }

    public function test_frame_ancestors_is_wildcard_when_empty(): void
    {
        $this->assertSame('*', DomainAllowlist::frameAncestors([]));
    }

    public function test_frame_ancestors_emits_both_schemes_for_exact_host(): void
    {
        $value = DomainAllowlist::frameAncestors(['acme.com']);

        $this->assertStringContainsString('https://acme.com', $value);
        $this->assertStringContainsString('http://acme.com', $value);
        $this->assertStringNotContainsString('*', $value);
    }

    public function test_frame_ancestors_emits_wildcard_and_apex_sources(): void
    {
        $value = DomainAllowlist::frameAncestors(['*.acme.com']);

        $this->assertStringContainsString('https://*.acme.com', $value);
        $this->assertStringContainsString('http://*.acme.com', $value);
        // Wildcard pattern also emits the apex, mirroring allows().
        $this->assertStringContainsString('https://acme.com', $value);
        $this->assertStringContainsString('http://acme.com', $value);
    }

    public function test_frame_ancestors_drops_csp_directive_injection(): void
    {
        // A stored value carrying a ";" or a space must not leak extra
        // directives/sources into the CSP header — the malicious entry is
        // dropped, leaving only the clean host.
        $value = DomainAllowlist::frameAncestors([
            'evil.com;frame-ancestors *',
            'evil.com sandbox',
            "acme.com\nfoo",
            'acme.com',
        ]);

        $this->assertStringNotContainsString(';', $value);
        $this->assertStringNotContainsString(' frame-ancestors', $value);
        $this->assertStringNotContainsString('sandbox', $value);
        $this->assertStringNotContainsString("\n", $value);
        $this->assertStringContainsString('https://acme.com', $value);
    }
}
