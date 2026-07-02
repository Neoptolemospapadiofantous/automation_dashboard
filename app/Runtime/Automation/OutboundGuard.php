<?php

namespace App\Runtime\Automation;

/**
 * SSRF guard for outbound automation webhooks — the #1 thing to get right.
 *
 * An automation URL is operator-supplied, so it is hostile input: left
 * unchecked it could point the server at its own loopback, the Docker
 * network, a private RFC1918 host, or the cloud metadata endpoint
 * (169.254.169.254) to exfiltrate credentials. assertSafe() is called
 * immediately before every request and throws BlockedAutomationUrl unless
 * the URL clears every check.
 *
 * Defenses, in order:
 *   1. Scheme allowlist — https only (http permitted ONLY when private hosts
 *      are allowed, i.e. local dev hitting http://localhost:5678).
 *   2. No embedded credentials (user:pass@host) — a classic parser-confusion
 *      and credential-leak vector.
 *   3. DNS resolution + per-address vetting — EVERY A/AAAA record the host
 *      resolves to must be a public address. Resolving here (not just
 *      inspecting the literal host) is what defeats a hostname that points at
 *      a private IP.
 *   4. Pinning — the returned `pin` entries (CURLOPT_RESOLVE format) let the
 *      caller force curl to connect to exactly the addresses vetted above
 *      instead of re-resolving, closing the DNS-rebinding TOCTOU window
 *      between this check and the request.
 *
 * allow_private_hosts (config) flips checks 1+3 off for local development.
 * It MUST stay false in production.
 */
class OutboundGuard
{
    /** @var callable(string): list<string>  Hostname → resolved IPs. */
    private $resolver;

    /**
     * @param  (callable(string): list<string>)|null  $resolver  Injectable for tests; defaults to real DNS.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::defaultResolver(...);
    }

    /**
     * Validate a target URL or throw BlockedAutomationUrl. Returns the parsed
     * components on success (so the caller doesn't re-parse), plus `pin`:
     * CURLOPT_RESOLVE entries ("host:port:ip1,ip2") the caller MUST pass to
     * curl so the request connects to these vetted addresses and never
     * re-resolves. Empty for literal-IP hosts (no DNS to rebind).
     *
     * @return array{scheme: string, host: string, ips: list<string>, pin: list<string>}
     */
    public function assertSafe(string $url): array
    {
        $allowPrivate = (bool) config('runtime.automation.allow_private_hosts', false);

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new BlockedAutomationUrl('Automation URL is not a valid absolute URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowedSchemes = $allowPrivate ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new BlockedAutomationUrl("Automation URL scheme '{$scheme}' is not allowed (https required).");
        }

        // Embedded credentials are never legitimate for a webhook target and
        // are a parser-confusion vector — reject outright.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedAutomationUrl('Automation URL must not contain embedded credentials.');
        }

        $host = (string) $parts['host'];

        // A bracketed/literal IP host skips DNS but still gets vetted.
        $literalIp = trim($host, '[]');
        $isLiteral = filter_var($literalIp, FILTER_VALIDATE_IP) !== false;
        if ($isLiteral) {
            $ips = [$literalIp];
        } else {
            $ips = ($this->resolver)($host);
            if ($ips === []) {
                throw new BlockedAutomationUrl("Automation host '{$host}' does not resolve.");
            }
        }

        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! $this->isPublicIp($ip)) {
                    throw new BlockedAutomationUrl(
                        "Automation host '{$host}' resolves to a non-public address and was blocked."
                    );
                }
            }
        }

        $pin = [];
        if (! $isLiteral) {
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $pin = ["{$host}:{$port}:".implode(',', $ips)];
        }

        return ['scheme' => $scheme, 'host' => $host, 'ips' => $ips, 'pin' => $pin];
    }

    /**
     * True only for a globally-routable unicast address. filter_var with
     * NO_PRIV_RANGE | NO_RES_RANGE rejects RFC1918, loopback, link-local
     * (169.254/16 + fe80::/10), and the reserved/benchmark ranges in one
     * shot — covering the metadata endpoint and the Docker bridge.
     */
    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Resolve a hostname to every IPv4 + IPv6 address. Returns [] on failure
     * (treated as "unresolvable" → blocked).
     *
     * @return list<string>
     */
    private static function defaultResolver(string $host): array
    {
        $ips = [];

        $a = @dns_get_record($host, DNS_A);
        foreach (is_array($a) ? $a : [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        // Fallback for environments where dns_get_record is restricted.
        if ($ips === []) {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }
}
