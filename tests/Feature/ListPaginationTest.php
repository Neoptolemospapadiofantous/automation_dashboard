<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the "load mechanism" on the high-volume list surfaces: the
 * conversation transcript loads only its most recent window (with a lazy
 * "load earlier" endpoint), and the leads board windows each kanban column
 * (with a per-column "load more" endpoint). Without these, both pages fetch
 * every row at once.
 */
class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Agent} */
    private function userWithAgent(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return [$user->fresh(), $agent];
    }

    public function test_transcript_loads_only_the_recent_window_with_more_flagged(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $conversation = Conversation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
        ]);
        for ($i = 1; $i <= 60; $i++) {
            Message::factory()->for($conversation, 'conversation')->create([
                'role' => $i % 2 === 0 ? 'agent' : 'user',
                'text' => "msg {$i}",
                'sequence' => $i,
            ]);
        }

        $this->actingAs($user)->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Conversations/Show')
                ->has('messages', 50)
                ->where('messagesHasMore', true)
                // Window is the newest 50 (sequences 11..60), rendered oldest-first.
                ->where('messages.0.sequence', 11)
            );
    }

    public function test_transcript_messages_endpoint_returns_earlier_batch(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $conversation = Conversation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
        ]);
        for ($i = 1; $i <= 60; $i++) {
            Message::factory()->for($conversation, 'conversation')->create([
                'role' => 'user', 'text' => "msg {$i}", 'sequence' => $i,
            ]);
        }

        // The first window starts at sequence 11; loading earlier than that
        // returns the remaining oldest 10 (sequences 1..10) with no more left.
        $this->actingAs($user)
            ->getJson(route('conversations.messages', $conversation).'?before=11')
            ->assertOk()
            ->assertJsonCount(10, 'messages')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('messages.0.sequence', 1)
            ->assertJsonPath('messages.9.sequence', 10);
    }

    public function test_transcript_messages_endpoint_is_tenant_scoped(): void
    {
        [$user, $agent] = $this->userWithAgent();
        $otherConversation = Conversation::factory()->create(); // different team

        $this->actingAs($user)
            ->getJson(route('conversations.messages', $otherConversation))
            ->assertForbidden();
    }

    public function test_board_windows_each_column_with_counts_and_more(): void
    {
        [$user, $agent] = $this->userWithAgent();

        Lead::factory()->count(30)->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
            'status' => 'new',
        ]);

        $this->actingAs($user)->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leads/Index')
                ->has('leads', 25) // first page only
                ->where('leadCounts.new', 30)
                ->where('leadHasMore.new', true)
                ->where('leadHasMore.qualified', false)
            );
    }

    public function test_board_endpoint_returns_next_page_for_a_column(): void
    {
        [$user, $agent] = $this->userWithAgent();

        Lead::factory()->count(30)->create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $agent->id,
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->getJson(route('leads.board', ['status' => 'new', 'offset' => 25]))
            ->assertOk()
            ->assertJsonCount(5, 'leads')
            ->assertJsonPath('has_more', false);
    }

    public function test_board_endpoint_rejects_unknown_status(): void
    {
        [$user] = $this->userWithAgent();

        $this->actingAs($user)
            ->getJson(route('leads.board', ['status' => 'bogus']))
            ->assertStatus(422);
    }
}
