<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\AgentRuntime;
use App\Runtime\Contracts\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Runtime contract resolves to the native engine — the only engine
 * since the Voiceflow surface was deleted. The contract stays as the
 * seam for any future engine.
 */
class DispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_contract_resolves_to_the_native_engine(): void
    {
        $this->assertInstanceOf(AgentRuntime::class, app(Runtime::class));
    }

    public function test_contract_serves_a_conversation_end_to_end(): void
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
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $traces = app(Runtime::class)->launch($agent, 'visitor-1');

        $this->assertSame('text', $traces[0]['type']);
        $this->assertStringContainsString('What brings you here', $traces[0]['payload']['message']);
    }

    public function test_health_reports_engine_native(): void
    {
        config([
            'runtime.llm.anthropic.api_key' => 'sk-test',
            'runtime.embeddings.openai_api_key' => 'sk-test',
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $h = app(Runtime::class)->health($agent);

        $this->assertTrue($h['ok']);
        $this->assertSame('native', $h['engine']);
    }
}
