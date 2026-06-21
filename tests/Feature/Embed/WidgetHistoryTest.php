<?php

namespace Tests\Feature\Embed;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persistent visitor identity: the embed widget's home screen lists the
 * visitor's own recent conversations (scoped to the stable visitor_token) and
 * can reopen one read-only. Token is the bearer capability — a visitor never
 * sees another visitor's or another team's chats.
 */
class WidgetHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $status = 'active'): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create(['status' => $status]);
    }

    private function conversation(Agent $agent, string $token, string $sessionId, array $attrs = []): Conversation
    {
        return Conversation::factory()->create(array_merge([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'visitor_id' => $sessionId,
            'visitor_token' => $token,
            'channel' => 'embed',
            'status' => 'ended',
            'last_message_at' => now(),
        ], $attrs));
    }

    public function test_history_requires_a_valid_token(): void
    {
        $agent = $this->makeAgent();

        $this->postJson("/embed/{$agent->slug}/history")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visitor_token']);

        $this->postJson("/embed/{$agent->slug}/history", ['visitor_token' => 'not-a-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visitor_token']);
    }

    public function test_history_404s_for_inactive_agent(): void
    {
        $draft = $this->makeAgent('draft');

        $this->postJson("/embed/{$draft->slug}/history", ['visitor_token' => 'embed-abcdefghijklmnop'])
            ->assertNotFound();
    }

    public function test_history_returns_the_visitors_last_five_newest_first(): void
    {
        $agent = $this->makeAgent();
        $token = 'embed-tokentokentokentoken';

        foreach (range(1, 6) as $i) {
            $this->conversation($agent, $token, "embed-sess-{$i}", [
                'last_message_at' => now()->subMinutes(6 - $i), // i=6 newest
            ]);
        }

        $this->postJson("/embed/{$agent->slug}/history", ['visitor_token' => $token])
            ->assertOk()
            ->assertJsonCount(5, 'conversations')
            ->assertJsonPath('conversations.0.id', Conversation::where('visitor_id', 'embed-sess-6')->value('id'));
    }

    public function test_history_is_scoped_to_token_and_excludes_others(): void
    {
        $agent = $this->makeAgent();
        $token = 'embed-mytokenmytokenmytoken';

        $mine = $this->conversation($agent, $token, 'embed-mine');
        // Another visitor on the same agent.
        $this->conversation($agent, 'embed-othertokenothertoken', 'embed-theirs');
        // A pre-token row (null visitor_token) must never match.
        $this->conversation($agent, $token, 'embed-legacy')->forceFill(['visitor_token' => null])->save();

        $this->postJson("/embed/{$agent->slug}/history", ['visitor_token' => $token])
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $mine->id);
    }

    public function test_history_title_is_the_first_user_message(): void
    {
        $agent = $this->makeAgent();
        $token = 'embed-titletokentitletoken';
        $conversation = $this->conversation($agent, $token, 'embed-titled');

        Message::factory()->for($conversation, 'conversation')->create([
            'role' => 'agent', 'text' => 'Hi there!', 'sequence' => 1,
        ]);
        Message::factory()->for($conversation, 'conversation')->create([
            'role' => 'user', 'text' => 'I need help with billing', 'sequence' => 2,
        ]);

        $this->postJson("/embed/{$agent->slug}/history", ['visitor_token' => $token])
            ->assertOk()
            ->assertJsonPath('conversations.0.title', 'I need help with billing');
    }

    public function test_transcript_returns_messages_for_an_owned_conversation(): void
    {
        $agent = $this->makeAgent();
        $token = 'embed-transtokentranstoken';
        $conversation = $this->conversation($agent, $token, 'embed-trans');

        Message::factory()->for($conversation, 'conversation')->create([
            'role' => 'agent', 'text' => 'Welcome', 'sequence' => 1,
        ]);
        Message::factory()->for($conversation, 'conversation')->create([
            'role' => 'user', 'text' => 'Hello', 'sequence' => 2,
        ]);

        $this->postJson("/embed/{$agent->slug}/conversation", ['visitor_token' => $token, 'conversation_id' => $conversation->id])
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'agent')
            ->assertJsonPath('messages.0.text', 'Welcome')
            ->assertJsonPath('messages.1.role', 'user');
    }

    public function test_transcript_404s_when_token_does_not_own_the_conversation(): void
    {
        $agent = $this->makeAgent();
        $conversation = $this->conversation($agent, 'embed-ownertokenownertoken', 'embed-owned');

        $this->postJson("/embed/{$agent->slug}/conversation", ['visitor_token' => 'embed-wrongtokenwrongtoken', 'conversation_id' => $conversation->id])
            ->assertNotFound();
    }

    public function test_recorder_stamps_and_backfills_the_visitor_token(): void
    {
        $agent = $this->makeAgent();
        $recorder = app(ConversationRecorder::class);

        $created = $recorder->resolve(
            teamId: (int) $agent->team_id,
            visitorId: 'embed-newsession1234567',
            channel: 'embed',
            agentId: $agent->id,
            visitorToken: 'embed-stabletoken12345678',
        );
        $this->assertSame('embed-stabletoken12345678', $created->fresh()->visitor_token);

        // A pre-token row gets the token back-filled when resolved again.
        $legacy = Conversation::factory()->create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-legacysession12345',
            'visitor_token' => null,
        ]);
        $recorder->resolve(
            teamId: (int) $agent->team_id,
            visitorId: 'embed-legacysession12345',
            channel: 'embed',
            agentId: $agent->id,
            visitorToken: 'embed-backfilltoken123456',
        );
        $this->assertSame('embed-backfilltoken123456', $legacy->fresh()->visitor_token);
    }

    public function test_transcript_404s_across_teams(): void
    {
        $token = 'embed-sharedtokensharedtok';
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $convoB = $this->conversation($agentB, $token, 'embed-bbb');

        // Same token value, but the conversation lives on agentB's team.
        $this->postJson("/embed/{$agentA->slug}/conversation", ['visitor_token' => $token, 'conversation_id' => $convoB->id])
            ->assertNotFound();
    }
}
