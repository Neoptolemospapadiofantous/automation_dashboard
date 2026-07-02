<?php

namespace Tests\Feature\Commands;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two CLI surfaces on the runtime: agents:terminal (single agent) and
 * agents:collab (multi-agent round-table). Anthropic is faked at the HTTP
 * layer so no turn hits the network.
 */
class AgentsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.embeddings.openai_api_key' => 'sk-openai-test',
        ]);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'CANNED REPLY']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob(base_path('data/agents/rooms/phpunit-*.json')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_terminal_runs_one_shot_message_and_bills(): void
    {
        $agent = $this->currentAgent(100);

        $this->artisan('agents:terminal', [
            '--team' => $agent->team_id,
            '--agent' => $agent->id,
            '--visitor' => 'phpunit-term',
            '--message' => 'hello',
        ])->assertExitCode(0);

        $this->assertTrue($agent->team->fresh()->credit_balance < 100);
    }

    public function test_terminal_no_bill_leaves_credits_untouched(): void
    {
        $agent = $this->currentAgent(100);

        $this->artisan('agents:terminal', [
            '--team' => $agent->team_id,
            '--agent' => $agent->id,
            '--visitor' => 'phpunit-term2',
            '--message' => 'hello',
            '--no-bill' => true,
        ])->assertExitCode(0);

        $this->assertSame(100, $agent->team->fresh()->credit_balance);
    }

    public function test_collab_round_table_runs_and_writes_ledger(): void
    {
        $a = $this->currentAgent(100);
        $b = $this->currentAgent(100);
        $room = 'phpunit-'.uniqid();

        $this->artisan('agents:collab', [
            '--agents' => $a->id.','.$b->id,
            '--topic' => 'plan a feature',
            '--rounds' => 1,
            '--room' => $room,
            '--no-bill' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(base_path('data/agents/rooms/'.$room.'.json'));
    }

    public function test_collab_rejects_unknown_agent(): void
    {
        $this->artisan('agents:collab', [
            '--agents' => 'does-not-exist',
            '--topic' => 'x',
            '--no-bill' => true,
        ])->assertExitCode(1);
    }

    private function currentAgent(int $balance): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => $balance])->save();

        return $agent->fresh()->load('team');
    }
}
