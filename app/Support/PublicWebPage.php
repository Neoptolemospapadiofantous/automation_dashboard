<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * SSRF-guarded fetch of a user-supplied public web page, stripped to plain
 * text for KB ingestion. Shared by the Knowledge page's "Add URL" action and
 * the onboarding website auto-ingest job — the guard rules must stay
 * identical for both, which is why this lives here and not in a controller.
 *
 * All user-recoverable failures throw \RuntimeException with a message safe
 * to surface verbatim; anything else bubbles as-is.
 */
class PublicWebPage
{
    /**
     * Fetch a public http(s) URL and return its readable text content.
     */
    public function fetchText(string $url): string
    {
        $this->assertPublicHttpUrl($url);

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'FlowstackBot/1.0'])
            ->withOptions(['allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                // Re-validate every hop so a public page can't 30x into
                // the private network (redirect-based SSRF bypass).
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    $this->assertPublicHttpUrl((string) $uri);
                },
            ]])
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('That URL returned HTTP '.$response->status().'.');
        }

        $content = $this->htmlToText($response->body());
        if (mb_strlen($content) < 40) {
            throw new \RuntimeException('That page had no readable text content.');
        }

        return $content;
    }

    /**
     * SSRF guard for user-supplied fetch targets. Enforces an http(s) scheme
     * and rejects any host that resolves to a private, loopback, link-local,
     * or otherwise reserved address. Throws \RuntimeException with a message
     * safe to surface back to the user.
     */
    public function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https URLs can be fetched.');
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '') {
            throw new \RuntimeException('That URL has no host to fetch.');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new \RuntimeException('Could not resolve that host.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('That URL points to a non-public address and cannot be fetched.');
            }
        }
    }

    /**
     * Resolve a host to its IP literals (A + AAAA). If the host is already an
     * IP literal it is returned as-is.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && $record[$key] !== '') {
                    $ips[] = (string) $record[$key];
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /**
     * Crude-but-effective HTML → text: drop script/style blocks, strip
     * tags, collapse whitespace. A readability-grade extractor can slot
     * in later; for marketing/docs pages this captures the substance.
     */
    protected function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
