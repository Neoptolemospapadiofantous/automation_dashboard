<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Exceptions\NotReady;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Exceptions\UpstreamUnavailable;
use App\Runtime\RuntimeDispatcher;
use App\Runtime\VoiceflowAdapter;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Verifies the umbrella RuntimeException + NotReady + UpstreamUnavailable
 * hierarchy is wired correctly. Controllers catch RuntimeException once
 * and get a clean 503 for any engine failure.
 */
class ExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_ready_extends_runtime_exception(): void
    {
        $e = new NotReady('phase 4 not shipped');

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame('phase 4 not shipped', $e->getMessage());
    }

    public function test_upstream_unavailable_extends_runtime_exception(): void
    {
        $e = new UpstreamUnavailable('voiceflow 502');

        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function test_agent_runtime_throws_not_ready_for_stubbed_methods(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $runtime = new AgentRuntime;

        try {
            $runtime->launch($agent, 'v1');
            $this->fail('Expected NotReady from launch');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(NotReady::class, $e);
        }

        try {
            $runtime->sendText($agent, 'v1', 'hi');
            $this->fail('Expected NotReady from sendText');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(NotReady::class, $e);
        }
    }

    public function test_voiceflow_adapter_normalizes_upstream_errors(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $voiceflow = $this->mock(VoiceflowService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('launch')->andThrow(new \RuntimeException('upstream 502'));
        });

        $adapter = new VoiceflowAdapter($voiceflow);

        try {
            $adapter->launch($agent, 'v1');
            $this->fail('Expected UpstreamUnavailable from adapter');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(UpstreamUnavailable::class, $e);
            $this->assertStringContainsString('launch', $e->getMessage());
            $this->assertStringContainsString('upstream 502', $e->getMessage());
        }
    }

    public function test_dispatcher_falls_back_to_voiceflow_for_unknown_mode(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        // Unknown / weird modes should route to Voiceflow (the safe default).
        foreach (['', 'foo-bar', 'partner_x'] as $weirdMode) {
            $agent = Agent::factory()->for($user->currentTeam)->create([
                'runtime_mode' => $weirdMode,
            ]);

            $h = app(RuntimeDispatcher::class)->health($agent);
            $this->assertSame('voiceflow', $h['engine'], "expected voiceflow for mode='{$weirdMode}'");
        }
    }

    public function test_dispatcher_memoizes_engine_per_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => Agent::RUNTIME_NATIVE,
        ]);

        $dispatcher = app(RuntimeDispatcher::class);

        // Calling engineFor (via the public health surface) twice should
        // return the same underlying engine — we assert via reflection
        // that the memoised array has exactly one entry afterward.
        config(['runtime.llm.anthropic.api_key' => 'sk', 'runtime.embeddings.openai_api_key' => 'sk']);
        $dispatcher->health($agent);
        $dispatcher->health($agent);

        $r = new \ReflectionProperty(RuntimeDispatcher::class, 'engines');
        $r->setAccessible(true);
        $cache = $r->getValue($dispatcher);
        $this->assertCount(1, $cache);
    }
}
