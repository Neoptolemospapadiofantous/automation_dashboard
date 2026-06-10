<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\NotReady;
use App\Runtime\RuntimeDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the dispatcher routes to the right engine based on
 * agents.runtime_mode. Phase 1 lands: native mode throws
 * NotReady on the stubbed methods (correct), voiceflow mode
 * delegates to the legacy VoiceflowService (already tested elsewhere).
 */
class DispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_contract_resolves_to_dispatcher(): void
    {
        $resolved = app(Runtime::class);

        $this->assertInstanceOf(RuntimeDispatcher::class, $resolved);
    }

    public function test_native_agent_routes_to_native_runtime(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
        ]);

        $dispatcher = app(RuntimeDispatcher::class);

        // Native runtime is still a Phase 1 stub for these methods,
        // so the call lands on NotReady — proving the routing
        // worked (and the legacy Voiceflow path wasn't picked).
        $this->expectException(NotReady::class);
        $dispatcher->launch($agent, 'visitor-1');
    }

    public function test_native_agent_health_reports_engine_native(): void
    {
        config([
            'runtime.llm.anthropic.api_key' => 'sk-test',
            'runtime.embeddings.openai_api_key' => 'sk-test',
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
        ]);

        $h = app(RuntimeDispatcher::class)->health($agent);

        $this->assertTrue($h['ok']);
        $this->assertSame('native', $h['engine']);
    }

    public function test_voiceflow_agent_health_reports_engine_voiceflow(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'voiceflow',
            'voiceflow_api_key' => 'VF.DM.test',
            'voiceflow_project_id' => 'proj-test',
        ]);

        $h = app(RuntimeDispatcher::class)->health($agent);

        $this->assertSame('voiceflow', $h['engine']);
    }
}
