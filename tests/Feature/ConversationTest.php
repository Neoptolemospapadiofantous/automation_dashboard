<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.test-key');
        config()->set('services.voiceflow.environment', 'main');
        config()->set('services.voiceflow.project_id', 'proj-123');
        config()->set('scout.driver', null); // don't hit a real search engine in tests
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_recorder_appends_messages_in_sequence(): void
    {
        $user = $this->user();
        $recorder = app(ConversationRecorder::class);

        $conversation = $recorder->resolve($user->currentTeam->id, 'web-1');
        $recorder->record($conversation, 'user', 'Hi');
        $recorder->record($conversation, 'agent', 'Hello! Name?');
        $recorder->record($conversation, 'user', 'Ada');

        $conversation->refresh();
        $this->assertSame(3, $conversation->message_count);
        $this->assertSame([1, 2, 3], $conversation->messages()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_recorder_resolves_one_conversation_per_user_and_team(): void
    {
        $user = $this->user();
        $recorder = app(ConversationRecorder::class);

        $a = $recorder->resolve($user->currentTeam->id, 'web-1');
        $b = $recorder->resolve($user->currentTeam->id, 'web-1');

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Conversation::count());
    }

    public function test_interact_persists_user_and_agent_messages(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['sessionKey' => 'sess-abc']),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => [
                ['type' => 'text', 'payload' => ['message' => 'Nice to meet you!']],
            ]]),
            'general-runtime.voiceflow.com/v4/project/*/state' => Http::response(['variables' => []]),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->postJson(route('chat.interact'), [
            'user_id' => 'web-42',
            'message' => 'Hello there',
        ])->assertOk();

        $conversationId = $response->json('conversation_id');
        $this->assertNotNull($conversationId);

        $conversation = Conversation::find($conversationId);
        $this->assertSame($user->currentTeam->id, $conversation->team_id);

        // One user message + one agent message recorded.
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationId, 'role' => 'user', 'text' => 'Hello there']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationId, 'role' => 'agent', 'text' => 'Nice to meet you!']);
        $this->assertSame(2, $conversation->fresh()->message_count);
    }

    public function test_conversation_index_is_agent_scoped(): void
    {
        // Phase G: conversations are filtered by current_agent_id, not
        // just team_id. Confirm both isolation boundaries hold.
        $user = $this->user();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $mine = Conversation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
        ]);
        // Same team, different agent — must NOT show.
        $otherAgent = Agent::factory()->for($user->currentTeam)->create();
        Conversation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $otherAgent->id,
        ]);
        // Different team — must NOT show.
        Conversation::factory()->create();

        $this->actingAs($user->fresh())->get(route('conversations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Conversations/Index')
                ->has('conversations.data', 1)
                ->where('conversations.data.0.id', $mine->id)
            );
    }
}
