<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The umbrella RuntimeException + Misconfigured + UpstreamUnavailable
 * hierarchy: controllers catch RuntimeException once and get a clean
 * 503 for any engine failure. These cases prove the runtime actually THROWS
 * the right type; the pure type-hierarchy checks live in
 * tests/Unit/Runtime/RuntimeExceptionHierarchyTest.php.
 */
class ExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_without_llm_key_throws_misconfigured(): void
    {
        // The default engine is Flowstack Core, which runs on OpenAI. Every
        // other provider is blanked too, so tier resolution has nothing to
        // degrade to and the missing Core key is what surfaces.
        config([
            'runtime.llm.openai.api_key' => '',
            'runtime.llm.anthropic.api_key' => '',
            'runtime.llm.google.api_key' => '',
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        try {
            app(AgentRuntime::class)->launch($agent, 'v1');
            $this->fail('Expected Misconfigured');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(Misconfigured::class, $e);
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }
    }

    public function test_provider_failure_surfaces_as_upstream_unavailable(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'overloaded']], 500)]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        try {
            app(AgentRuntime::class)->launch($agent, 'v1');
            $this->fail('Expected UpstreamUnavailable');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(UpstreamUnavailable::class, $e);
        }
    }
}
