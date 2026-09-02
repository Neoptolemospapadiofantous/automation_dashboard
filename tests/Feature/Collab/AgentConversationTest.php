<?php

namespace Tests\Feature\Collab;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Collab\AgentConversation;
use App\Runtime\Collab\RoomLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The agent-to-agent comms primitive: relay carries one agent's reply into the
 * next agent, every turn is recorded to the room ledger, and each speaking
 * agent's own team is billed.
 */
class AgentConversationTest extends TestCase
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
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'CANNED REPLY'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
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

    public function test_relay_returns_replies_records_ledger_and_bills(): void
    {
        $agent = $this->agentWithCredits(100);
        $room = 'phpunit-'.uniqid();

        $replies = app(AgentConversation::class)->relay($agent, 'hello there', $room);

        $this->assertNotEmpty($replies, 'relay should return at least one reply line');
        $this->assertTrue($agent->team->fresh()->credit_balance < 100, 'team should be billed for the turn');

        $turns = app(RoomLedger::class)->read($room)['turns'];
        $this->assertCount(1, $turns);
        $this->assertSame($agent->id, $turns[0]['agent_id']);
        $this->assertSame('hello there', $turns[0]['in']);
    }

    public function test_roundtable_passes_previous_reply_into_the_next_agent(): void
    {
        $a = $this->agentWithCredits(100);
        $b = $this->agentWithCredits(100);
        $room = 'phpunit-'.uniqid();

        $transcript = app(AgentConversation::class)->roundtable([$a, $b], 'kick-off topic', 1, $room);

        $this->assertCount(2, $transcript);

        $turns = app(RoomLedger::class)->read($room)['turns'];
        // First agent is handed the raw topic…
        $this->assertSame('kick-off topic', $turns[0]['in']);
        // …the second agent is handed the first agent's reply text.
        $this->assertStringContainsString($turns[0]['text'], $turns[1]['in']);

        // Each agent's own team was billed.
        $this->assertTrue($a->team->fresh()->credit_balance < 100);
        $this->assertTrue($b->team->fresh()->credit_balance < 100);
    }

    public function test_no_bill_flag_leaves_credits_untouched(): void
    {
        $agent = $this->agentWithCredits(100);
        $room = 'phpunit-'.uniqid();

        app(AgentConversation::class)->relay($agent, 'hi', $room, bill: false);

        $this->assertSame(100, $agent->team->fresh()->credit_balance);
    }

    private function agentWithCredits(int $balance): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => $balance])->save();

        return $agent->fresh()->load('team');
    }
}
