<?php

namespace App\Support\Embed;

/**
 * Matches a request's host against an agent's allowed-domains list for the
 * embeddable widget.
 *
 * Trust boundary (be honest about it): the widget runs on the customer's
 * page, but the chat iframe + its launch/interact calls run on OUR origin,
 * so the browser's Origin header can't tell us the customer's domain. The
 * loader (which DOES run on the customer page) forwards the host; this class
 * validates it. That stops honest misuse and accidental cross-site embeds.
 * It is NOT a cryptographic guarantee against a hand-crafted direct API
 * call — the per-IP throttle, the free-greeting cap, and the credit ceiling
 * remain the backstops for deliberate abuse.
 *
 * Rules:
 *   - empty allowlist  → allow everything (permissive, backward-compatible)
 *   - "acme.com"       → matches the apex only
 *   - "*.acme.com"     → matches any subdomain AND the apex
 *   - localhost / 127.0.0.1 are matched literally (handy for local testing)
 */
class DomainAllowlist
{
    /**
     * @param  list<string>  $patterns  the agent's allowed_domains
     * @param  string|null  $candidate  an Origin/Referer URL or a bare host
     */
    public static function allows(array $patterns, ?string $candidate): bool
    {
        if ($patterns === []) {
            return true; // no restriction configured
        }

        $host = self::host($candidate);
        if ($host === null) {
            return false; // restricted but no usable host → deny
        }

        foreach ($patterns as $pattern) {
            if (self::matches(strtolower(trim($pattern)), $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a lowercase hostname from a URL, an Origin, or a bare host.
     * Returns null when nothing host-like can be found.
     */
    public static function host(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Bare host (no scheme) — parse_url needs a scheme to find the host.
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * Build a CSP `frame-ancestors` value from the allowlist — the
     * browser-enforced (unspoofable) control over which sites may iframe
     * the chat. Empty allowlist → '*' (embeddable anywhere).
     *
     * Each host is emitted for both https and http (http covers localhost /
     * staging over plain HTTP). A "*.acme.com" pattern also emits the apex,
     * matching allows().
     *
     * @param  list<string>  $patterns
     */
    public static function frameAncestors(array $patterns): string
    {
        if ($patterns === []) {
            return '*';
        }

        $sources = [];
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            // Defense in depth: only bare hosts (optionally "*." prefixed) may
            // reach the CSP header. Anything with a space, ";", or control char
            // is dropped so it can't inject extra directives, regardless of how
            // it got stored.
            if ($pattern === '' || preg_match('/^\*?[a-z0-9.-]+$/', $pattern) !== 1) {
                continue;
            }
            foreach (['https://', 'http://'] as $scheme) {
                $sources[] = $scheme.$pattern;
                if (str_starts_with($pattern, '*.')) {
                    $sources[] = $scheme.substr($pattern, 2); // apex too
                }
            }
        }

        $sources = array_values(array_unique($sources));

        return $sources === [] ? "'none'" : implode(' ', $sources);
    }

    private static function matches(string $pattern, string $host): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, '*.')) {
            $base = substr($pattern, 2);          // "*.acme.com" → "acme.com"

            return $host === $base || str_ends_with($host, '.'.$base);
        }

        return $host === $pattern;
    }
}
