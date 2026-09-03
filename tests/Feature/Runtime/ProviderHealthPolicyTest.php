<?php

namespace Tests\Feature\Runtime;

use App\Console\Commands\ProviderHealthCheck;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The health check has to agree with the billing policy, because it is the
 * thing that decides whether a missing key is an incident.
 *
 * Since every premium tier became byok_only, the platform deliberately holds
 * no Anthropic or Google key — that is the design, and prod runs that way. A
 * check that keeps calling it a warning trains everyone to ignore the digest,
 * so it reads the tier config rather than assuming.
 */
class ProviderHealthPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The bridge branch short-circuits the Anthropic check; prod has it
        // off, and that is the case under test.
        config([
            'runtime.llm.bridge.enabled' => false,
            'runtime.llm.anthropic.api_key' => '',
            'runtime.llm.google.api_key' => '',
        ]);
    }

    private function checks(): array
    {
        $cmd = new ProviderHealthCheck;

        $anthropic = (fn () => $this->checkAnthropic())->call($cmd);
        $gemini = (fn () => $this->checkGemini())->call($cmd);

        return ['anthropic' => $anthropic, 'gemini' => $gemini];
    }

    public function test_a_missing_key_for_a_byok_only_provider_is_not_a_warning(): void
    {
        Http::preventStrayRequests();

        $out = $this->checks();

        $this->assertSame('PASS', $out['anthropic']['status']);
        $this->assertStringContainsString('by design', $out['anthropic']['detail']);
        $this->assertStringContainsString('byok_only', $out['anthropic']['detail']);

        $this->assertSame('PASS', $out['gemini']['status']);
        $this->assertStringContainsString('by design', $out['gemini']['detail']);
    }

    public function test_a_missing_key_still_warns_when_the_platform_would_serve_that_tier(): void
    {
        Http::preventStrayRequests();

        // Put one Anthropic tier back on platform billing: now the absent key
        // really does mean a tier nobody can serve.
        config(['runtime.tiers.haiku.byok_only' => false]);

        $out = $this->checks();

        $this->assertSame('WARN', $out['anthropic']['status']);
        $this->assertStringContainsString('no Claude tier can be served', $out['anthropic']['detail']);

        // Google is untouched, so it stays by-design.
        $this->assertSame('PASS', $out['gemini']['status']);
    }

    public function test_the_verdict_is_derived_from_config_not_hardcoded_per_provider(): void
    {
        Http::preventStrayRequests();

        config(['runtime.tiers.gemini.byok_only' => false]);

        $out = $this->checks();

        $this->assertSame('WARN', $out['gemini']['status']);
        $this->assertStringContainsString('GEMINI_API_KEY is not set', $out['gemini']['detail']);
        $this->assertSame('PASS', $out['anthropic']['status']);
    }
}
