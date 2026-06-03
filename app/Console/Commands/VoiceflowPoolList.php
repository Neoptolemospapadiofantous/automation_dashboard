<?php

namespace App\Console\Commands;

use App\Models\VoiceflowProjectPoolEntry;
use App\Provisioning\PoolAllocator;
use Illuminate\Console\Command;

/**
 * Operator command — show the state of the Voiceflow project pool.
 *
 *   php artisan vf:pool:list
 *
 * Pair with a cron + Slack/email alert when `available` drops below a
 * threshold so signups never hit `PoolExhausted` unexpectedly.
 */
class VoiceflowPoolList extends Command
{
    protected $signature = 'vf:pool:list {--available-min=10 : Exit code 1 when available is below this}';

    protected $description = 'Show the Voiceflow project pool counts and recent entries.';

    public function handle(PoolAllocator $allocator): int
    {
        $counts = $allocator->counts();

        $this->table(
            ['Status', 'Count'],
            [
                ['available', $counts['available']],
                ['assigned', $counts['assigned']],
                ['retired', $counts['retired']],
                ['total', $counts['total']],
            ],
        );

        $rows = VoiceflowProjectPoolEntry::query()
            ->latest()
            ->limit(20)
            ->get(['id', 'voiceflow_project_id', 'status', 'assigned_to_agent_id', 'assigned_at', 'created_at']);

        if ($rows->isNotEmpty()) {
            $this->newLine();
            $this->info('Most recent 20 entries:');
            $this->table(
                ['id', 'project_id', 'status', 'agent', 'assigned_at', 'created_at'],
                $rows->map(fn ($r) => [
                    $r->id,
                    $r->voiceflow_project_id,
                    $r->status,
                    $r->assigned_to_agent_id ?? '—',
                    $r->assigned_at?->format('Y-m-d H:i') ?? '—',
                    $r->created_at->format('Y-m-d H:i'),
                ])->all(),
            );
        }

        $min = (int) $this->option('available-min');
        if ($counts['available'] < $min) {
            $this->error("Available pool entries ({$counts['available']}) is below threshold ({$min}). Add more via vf:pool:add.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
