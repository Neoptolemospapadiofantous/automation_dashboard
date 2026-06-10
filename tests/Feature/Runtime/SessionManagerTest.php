<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_replaces_existing_session(): void
    {
        [$agent, $m] = $this->scaffold();

        $first = $m->reset($agent, 'v1', 'greeting');
        $first->forceFill(['variables' => ['name' => 'Alice'], 'flow_state' => 'discovery'])->save();

        $second = $m->reset($agent, 'v1', 'greeting');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('greeting', $second->flow_state);
        $this->assertSame([], (array) $second->variables);
        $this->assertSame(1, RuntimeSession::where('agent_id', $agent->id)->count());
    }

    public function test_find_or_create_is_idempotent(): void
    {
        [$agent, $m] = $this->scaffold();

        $a = $m->findOrCreate($agent, 'v1', 'greeting');
        $b = $m->findOrCreate($agent, 'v1', 'greeting');

        $this->assertSame($a->id, $b->id);
    }

    public function test_append_history_trims_from_front_and_repairs_tool_result_head(): void
    {
        config(['runtime.session.history_limit' => 6]);
        [$agent, $m] = $this->scaffold();
        $session = $m->reset($agent, 'v1', 'greeting');

        // Build 8 entries: positions 2 and 3 form a tool_use/tool_result pair.
        $entries = [
            ['role' => 'user', 'content' => 'msg-0'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a-1']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'x', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'ok', 'is_error' => false]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a-2']]],
            ['role' => 'user', 'content' => 'msg-1'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a-3']]],
            ['role' => 'user', 'content' => 'msg-2'],
        ];
        $m->appendHistory($session, $entries);

        $history = $session->fresh()->history;
        $this->assertLessThanOrEqual(6, count($history));
        // History must never START with a dangling tool_result.
        $first = $history[0]['content'] ?? null;
        if (is_array($first)) {
            $this->assertNotSame('tool_result', $first[0]['type'] ?? '');
        }
        // Most recent entry always survives.
        $this->assertSame('msg-2', end($history)['content']);
    }

    public function test_user_turn_count_only_counts_real_user_text(): void
    {
        [$agent, $m] = $this->scaffold();
        $session = $m->reset($agent, 'v1', 'greeting');

        $m->appendHistory($session, [
            ['role' => 'user', 'content' => 'real message'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'reply']]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => 'x', 'is_error' => false]]],
            ['role' => 'user', 'content' => 'second real'],
        ]);

        $this->assertSame(2, $m->userTurnCount($session->fresh()));
    }

    public function test_end_is_idempotent(): void
    {
        [$agent, $m] = $this->scaffold();
        $m->reset($agent, 'v1', 'greeting');

        $m->end($agent, 'v1');
        $m->end($agent, 'v1'); // second call: no exception, no change
        $m->end($agent, 'missing-visitor'); // absent row: still fine

        $this->assertSame('ended', RuntimeSession::where('visitor_id', 'v1')->first()->flow_state);
    }

    public function test_prune_deletes_only_idle_sessions(): void
    {
        [$agent, $m] = $this->scaffold();

        $stale = $m->reset($agent, 'stale', 'greeting');
        $stale->forceFill(['last_activity_at' => now()->subDays(45)])->save();

        $fresh = $m->reset($agent, 'fresh', 'greeting');
        $fresh->forceFill(['last_activity_at' => now()->subDays(2)])->save();

        $deleted = $m->prune(30);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('runtime_sessions', ['visitor_id' => 'stale']);
        $this->assertDatabaseHas('runtime_sessions', ['visitor_id' => 'fresh']);
    }

    public function test_prune_command_runs(): void
    {
        $this->artisan('runtime:prune-sessions')
            ->expectsOutputToContain('Pruned 0 runtime session(s).')
            ->assertExitCode(0);
    }

    /** @return array{0: Agent, 1: SessionManager} */
    private function scaffold(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);

        return [$agent, app(SessionManager::class)];
    }
}
