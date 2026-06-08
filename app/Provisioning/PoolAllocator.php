<?php

namespace App\Provisioning;

use App\Models\Agent;
use App\Models\VoiceflowProjectPoolEntry;
use Illuminate\Support\Facades\DB;

/**
 * Allocates pre-created Voiceflow projects from the pool to new agents.
 *
 * The transactional `allocate()` is the single hot path: opens a row-lock
 * on the next available entry, marks it assigned + records the agent id,
 * returns the entry so CreateAgent can stamp the agent with its keys.
 * Row-lock prevents two concurrent signups grabbing the same project.
 *
 * `release()` happens on agent deletion. We don't put the project back
 * into "available" — the customer's KB documents, conversation state,
 * and analytics are still inside the Voiceflow project, and re-assigning
 * it to a different tenant would leak that data. The operator must
 * either reset the project in Voiceflow first or retire it permanently.
 */
class PoolAllocator
{
    /**
     * Reserve the next available pool entry for an agent.
     *
     * @throws PoolExhausted when no available entries remain.
     */
    public function allocate(Agent $agent): VoiceflowProjectPoolEntry
    {
        return DB::transaction(function () use ($agent) {
            $entry = VoiceflowProjectPoolEntry::query()
                ->where('status', VoiceflowProjectPoolEntry::STATUS_AVAILABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                throw new PoolExhausted;
            }

            $entry->forceFill([
                'status' => VoiceflowProjectPoolEntry::STATUS_ASSIGNED,
                'assigned_to_agent_id' => $agent->id,
                'assigned_at' => now(),
            ])->save();

            return $entry;
        });
    }

    /**
     * Mark the entry retired when the agent it backs is deleted. NOT
     * returned to "available" — see class docstring.
     */
    public function release(Agent $agent): void
    {
        VoiceflowProjectPoolEntry::query()
            ->where('assigned_to_agent_id', $agent->id)
            ->update([
                'status' => VoiceflowProjectPoolEntry::STATUS_RETIRED,
                'assigned_at' => null,
                // FK constraint nulls this on agent delete, but we run inside
                // the same transaction as the delete — null explicitly so the
                // row reflects intent regardless of the delete order.
                'assigned_to_agent_id' => null,
            ]);
    }

    /**
     * @return array{available: int, assigned: int, retired: int, total: int}
     */
    public function counts(): array
    {
        $rows = VoiceflowProjectPoolEntry::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'available' => (int) ($rows[VoiceflowProjectPoolEntry::STATUS_AVAILABLE] ?? 0),
            'assigned' => (int) ($rows[VoiceflowProjectPoolEntry::STATUS_ASSIGNED] ?? 0),
            'retired' => (int) ($rows[VoiceflowProjectPoolEntry::STATUS_RETIRED] ?? 0),
            'total' => (int) $rows->sum(),
        ];
    }
}
