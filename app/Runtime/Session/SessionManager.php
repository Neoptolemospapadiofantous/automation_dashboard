<?php

namespace App\Runtime\Session;

use App\Models\Agent;
use App\Runtime\Models\RuntimeSession;

/**
 * Owns the lifecycle of runtime sessions: create/reset on launch, load on
 * each turn, append trimmed LLM history, count turns for the safety cap,
 * idempotent end, and bulk pruning of idle rows.
 *
 * Transitions (flow_state writes) are owned by the FlowExecutor; this
 * class only persists what the executor decides.
 */
class SessionManager
{
    /**
     * Fresh session for a launch — deletes any existing (agent, visitor)
     * row first, matching Voiceflow's reset-on-launch semantics (the embed
     * iframe calls launch on every open; the greeting replays).
     */
    public function reset(Agent $agent, string $visitorId, string $initialState): RuntimeSession
    {
        RuntimeSession::query()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $visitorId)
            ->delete();

        return RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => $initialState,
            'variables' => [],
            'history' => [],
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Load for a turn; creates a fresh one when the visitor skipped
     * launch (e.g. a direct interact call after the session was pruned).
     */
    public function findOrCreate(Agent $agent, string $visitorId, string $initialState): RuntimeSession
    {
        return RuntimeSession::query()->firstOrCreate(
            ['agent_id' => $agent->id, 'visitor_id' => $visitorId],
            ['flow_state' => $initialState, 'variables' => [], 'history' => [], 'last_activity_at' => now()],
        );
    }

    /**
     * Append LLM-format entries to the session history, trimmed from the
     * FRONT to the configured limit so recent context survives.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function appendHistory(RuntimeSession $session, array $entries): void
    {
        $history = array_merge((array) ($session->history ?? []), $entries);

        $limit = max(4, (int) config('runtime.session.history_limit'));
        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
            // Never let history start with a dangling tool_result (its
            // paired tool_use was trimmed away) — Anthropic rejects that.
            while ($history !== [] && $this->startsWithToolResult($history[0])) {
                array_shift($history);
            }
        }

        $session->history = $history;
        $session->last_activity_at = now();
        $session->save();
    }

    /**
     * How many user turns this session has consumed (safety cap input).
     */
    public function userTurnCount(RuntimeSession $session): int
    {
        $count = 0;
        foreach ((array) ($session->history ?? []) as $entry) {
            if (($entry['role'] ?? '') === 'user' && is_string($entry['content'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Idempotent close. Keeps the row (analytics + dashboard replay);
     * pruning removes it after the idle window.
     */
    public function end(Agent $agent, string $visitorId): void
    {
        RuntimeSession::query()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $visitorId)
            ->update(['flow_state' => 'ended', 'last_activity_at' => now()]);
    }

    /**
     * Delete sessions idle beyond the retention window. Returns the count.
     */
    public function prune(?int $days = null): int
    {
        $days ??= (int) config('runtime.session.prune_days');
        $cutoff = now()->subDays(max(1, $days));

        return RuntimeSession::query()
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff): void {
                        $q2->whereNull('last_activity_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function startsWithToolResult(array $entry): bool
    {
        $content = $entry['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }
        $first = $content[0] ?? null;

        return is_array($first) && ($first['type'] ?? '') === 'tool_result';
    }
}
