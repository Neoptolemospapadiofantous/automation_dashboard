<?php

namespace Tests\Feature\Embed;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post-chat rating: the widget's bad/ok/good + comment prompt records onto the
 * visitor's conversation (ending it), and the operator dashboard surfaces the
 * last 5 rated conversations per agent.
 */
class WidgetFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $status = 'active'): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['credit_balance' => 1000])->save();

        return Agent::factory()->for($team)->create(['status' => $status]);
    }

    private function conversation(Agent $agent, string $visitorId): Conversation
    {
        return Conversation::factory()->create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'channel' => 'embed',
            'status' => 'active',
        ]);
    }

    public function test_feedback_requires_a_valid_visitor_and_rating(): void
    {
        $agent = $this->makeAgent();

        $this->postJson("/embed/{$agent->slug}/feedback", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visitor_id', 'rating']);
    }

    public function test_feedback_rejects_an_unknown_rating_value(): void
    {
        $agent = $this->makeAgent();

        $this->postJson("/embed/{$agent->slug}/feedback", [
            'visitor_id' => 'embed-abc123',
            'rating' => 'great',
        ])->assertStatus(422)->assertJsonValidationErrors(['rating']);
    }

    public function test_feedback_404s_for_inactive_agent(): void
    {
        $draft = $this->makeAgent('draft');

        $this->postJson("/embed/{$draft->slug}/feedback", [
            'visitor_id' => 'embed-abc123',
            'rating' => 'good',
        ])->assertNotFound();
    }

    public function test_feedback_stores_rating_and_ends_the_conversation(): void
    {
        $agent = $this->makeAgent();
        $conversation = $this->conversation($agent, 'embed-abc123');

        $this->postJson("/embed/{$agent->slug}/feedback", [
            'visitor_id' => 'embed-abc123',
            'rating' => 'good',
            'comment' => '  Super helpful, thanks!  ',
        ])->assertOk()->assertJsonPath('ok', true);

        $fresh = $conversation->fresh();
        $this->assertSame('good', $fresh->rating);
        $this->assertSame('Super helpful, thanks!', $fresh->feedback_comment);
        $this->assertNotNull($fresh->rated_at);
        $this->assertSame('ended', $fresh->status);
    }

    public function test_feedback_without_comment_stores_null(): void
    {
        $agent = $this->makeAgent();
        $conversation = $this->conversation($agent, 'embed-abc123');

        $this->postJson("/embed/{$agent->slug}/feedback", [
            'visitor_id' => 'embed-abc123',
            'rating' => 'bad',
        ])->assertOk();

        $fresh = $conversation->fresh();
        $this->assertSame('bad', $fresh->rating);
        $this->assertNull($fresh->feedback_comment);
    }

    public function test_feedback_succeeds_quietly_when_no_conversation_exists(): void
    {
        $agent = $this->makeAgent();

        // No conversation recorded for this visitor (already reset / never
        // chatted): the widget still resets, so the endpoint must not error.
        $this->postJson("/embed/{$agent->slug}/feedback", [
            'visitor_id' => 'embed-ghost',
            'rating' => 'ok',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_interact_reports_not_ended_during_an_active_chat(): void
    {
        $agent = $this->makeAgent();

        $this->fakeCore([['text' => 'Got it!', 'in' => 5, 'out' => 5]]);

        $this->postJson("/embed/{$agent->slug}/interact", [
            'visitor_id' => 'embed-abc123',
            'message' => 'Hello',
        ])->assertOk()
            ->assertJsonStructure(['traces', 'ended'])
            ->assertJsonPath('ended', false);
    }

    public function test_dashboard_shows_last_five_rated_conversations_for_current_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $other = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        // 6 rated for the current agent — only the latest 5 should surface.
        foreach (range(1, 6) as $i) {
            Conversation::factory()->create([
                'team_id' => $team->id,
                'agent_id' => $agent->id,
                'visitor_id' => "embed-cur-{$i}",
                'rating' => 'good',
                'feedback_comment' => "comment {$i}",
                'rated_at' => now()->subMinutes(6 - $i), // i=6 is most recent
            ]);
        }

        // A rated conversation on a DIFFERENT agent must not leak in.
        Conversation::factory()->create([
            'team_id' => $team->id,
            'agent_id' => $other->id,
            'visitor_id' => 'embed-other',
            'rating' => 'bad',
            'rated_at' => now(),
        ]);

        // An unrated conversation on the current agent must not appear either.
        Conversation::factory()->create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-unrated',
        ]);

        $this->actingAs($user->fresh())->get('/conversations')
            ->assertInertia(fn ($page) => $page
                ->component('Conversations/Index')
                ->has('feedback', 5)
                // Ordered by rated_at desc: the most recent (i=6) is first.
                ->where('feedback.0.name', 'embed-cur-6')
                ->where('feedback.0.rating', 'good')
                ->where('feedback.0.comment', 'comment 6')
            );
    }
}
