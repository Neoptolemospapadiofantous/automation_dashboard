<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SlackAgentTurnTest extends TestCase
{
    use RefreshDatabase;

    public function test_503s_when_token_is_not_configured(): void
    {
        config(['services.slack.agent_turn_token' => '']);

        $this->turn()->assertStatus(503);
    }

    public function test_503s_in_production_even_when_configured(): void
    {
        $this->makeAgent();
        $this->app['env'] = 'production';

        $this->turn()->assertStatus(503);
    }

    public function test_401s_on_missing_or_wrong_bearer_token(): void
    {
        $this->makeAgent();

        $this->turn(token: null)->assertStatus(401);
        $this->turn(token: 'wrong-token')->assertStatus(401);
    }

    public function test_validates_required_fields(): void
    {
        $this->makeAgent();

        $this->postJson('/api/slack/agent-turn', [], ['Authorization' => 'Bearer secret-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['surface', 'user', 'channel', 'text']);
    }

    public function test_402s_when_team_out_of_credits(): void
    {
        $agent = $this->makeAgent();
        $agent->team->forceFill(['credit_balance' => 0])->save();

        $this->turn()->assertStatus(402);
    }

    public function test_503s_when_team_has_no_active_current_agent(): void
    {
        $agent = $this->makeAgent();
        $agent->forceFill(['status' => Agent::STATUS_DRAFT])->save();

        $this->turn()->assertStatus(503);
    }

    public function test_turn_replies_debits_credits_and_records_conversation(): void
    {
        $agent = $this->makeAgent();

        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Got it!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->turn()
            ->assertOk()
            ->assertJsonPath('reply', 'Got it!');

        // (1 user message + 1 reply) × haiku multiplier 1 = 2 credits.
        $this->assertSame(80, $agent->team->fresh()->credit_balance);

        $conversation = Conversation::where('team_id', $agent->team_id)
            ->where('visitor_id', 'slack:U0123ABC:C0456DEF')
            ->first();
        $this->assertNotNull($conversation);
        $this->assertSame('slack', $conversation->channel);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_same_user_and_channel_reuse_one_conversation(): void
    {
        $agent = $this->makeAgent();

        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Got it!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->turn()->assertOk();
        $this->turn(['text' => 'Follow-up'])->assertOk();

        $this->assertSame(1, Conversation::where('team_id', $agent->team_id)->count());
    }

    private function turn(array $overrides = [], ?string $token = 'secret-token'): TestResponse
    {
        return $this->postJson('/api/slack/agent-turn', array_merge([
            'surface' => 'slack',
            'user' => 'U0123ABC',
            'channel' => 'C0456DEF',
            'text' => 'Hello',
        ], $overrides), $token === null ? [] : ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * Active agent wired as its team's current agent, with the shared-token
     * config pointed at that team.
     */
    private function makeAgent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 100])->save();

        $agent = Agent::factory()->for($team)->create(['status' => Agent::STATUS_ACTIVE]);
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        config([
            'services.slack.agent_turn_token' => 'secret-token',
            'services.slack.agent_turn_team_id' => $team->id,
        ]);

        return $agent;
    }
}
