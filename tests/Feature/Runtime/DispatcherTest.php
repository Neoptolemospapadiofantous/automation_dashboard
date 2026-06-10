<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Runtime\RuntimeDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the dispatcher routes to the right engine based on
 * agents.runtime_mode: native agents reach the native engine (proven by
 * a faked Anthropic round-trip), everything else stays on Voiceflow.
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
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hi there! What brings you here today?']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 9],
            ]),
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
        ]);

        $traces = app(RuntimeDispatcher::class)->launch($agent, 'visitor-1');

        $this->assertSame('text', $traces[0]['type']);
        $this->assertStringContainsString('What brings you here', $traces[0]['payload']['message']);
        // And nothing went anywhere near Voiceflow.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'voiceflow.com'));
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
