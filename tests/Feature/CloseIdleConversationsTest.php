<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseIdleConversationsTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->withPersonalTeam()->create();
        $this->agent = Agent::factory()->for($user->currentTeam)->create();
        config([
            'runtime.auto_close.close_after_minutes' => 120,
            'runtime.auto_close.handoff_close_after_minutes' => 1440,
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function conversation(int $idleMinutes, array $meta = [], string $status = 'active'): Conversation
    {
        return Conversation::factory()->create([
            'team_id' => $this->agent->team_id,
            'agent_id' => $this->agent->id,
            'status' => $status,
            'started_at' => now()->subMinutes($idleMinutes + 5),
            'last_message_at' => now()->subMinutes($idleMinutes),
            'ended_at' => $status === 'ended' ? now()->subMinutes($idleMinutes) : null,
            'meta' => $meta,
        ]);
    }

    public function test_closes_an_idle_chat_and_leaves_a_fresh_one_alone(): void
    {
        $idle = $this->conversation(180);
        $fresh = $this->conversation(10);

        $this->artisan('conversations:auto-close')->assertSuccessful();

        $idle->refresh();
        $this->assertSame('ended', $idle->status);
        $this->assertNotNull($idle->ended_at);
        $this->assertSame('idle', $idle->meta['auto_closed']['reason']);
        $this->assertGreaterThanOrEqual(180, $idle->meta['auto_closed']['idle_minutes']);

        $this->assertSame('active', $fresh->refresh()->status);
    }

    public function test_never_closes_a_chat_a_teammate_has_taken_over(): void
    {
        // Silence during a takeover means the teammate is typing or checking
        // something — closing would pull the chat out from under them.
        $taken = $this->conversation(5000, ['human_takeover' => true]);

        $this->artisan('conversations:auto-close')
            ->expectsOutputToContain('1 under takeover left open')
            ->assertSuccessful();

        $this->assertSame('active', $taken->refresh()->status);
    }

    public function test_an_unanswered_handoff_gets_the_longer_fuse(): void
    {
        // Past the normal window but inside the handoff one: still waiting.
        $waiting = $this->conversation(200, ['handoff_requested' => true]);
        // Past the handoff window too: nobody ever came.
        $abandoned = $this->conversation(2000, ['handoff_requested' => true]);

        $this->artisan('conversations:auto-close')
            ->expectsOutputToContain('1 still waiting on a human')
            ->assertSuccessful();

        $this->assertSame('active', $waiting->refresh()->status);
        $this->assertSame('ended', $abandoned->refresh()->status);
        $this->assertSame('idle, handoff never answered', $abandoned->meta['auto_closed']['reason']);
    }

    public function test_dry_run_changes_nothing_and_already_ended_chats_are_skipped(): void
    {
        $idle = $this->conversation(300);
        $ended = $this->conversation(300, [], 'ended');

        $this->artisan('conversations:auto-close', ['--dry' => true])
            ->expectsOutputToContain('Would close 1 conversation(s)')
            ->assertSuccessful();

        $this->assertSame('active', $idle->refresh()->status);
        $this->assertArrayNotHasKey('auto_closed', (array) $ended->refresh()->meta);
    }
}
