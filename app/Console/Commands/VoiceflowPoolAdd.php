<?php

namespace App\Console\Commands;

use App\Models\VoiceflowProjectPoolEntry;
use Illuminate\Console\Command;

/**
 * Operator command — add a freshly-created Voiceflow project to the pool.
 *
 *   php artisan vf:pool:add \
 *     --project-id=6a1fc1c355d749825f0a3f30 \
 *     --api-key=VF.DM.xxx.yyy \
 *     [--workspace-key=VF.WS.zzz] \
 *     [--environment=main] \
 *     [--notes="Slot 42, added 2026-06-04"]
 *
 * Prompts interactively for missing values when run without flags so the
 * operator can paste from the Voiceflow UI safely.
 */
class VoiceflowPoolAdd extends Command
{
    protected $signature = 'vf:pool:add
        {--project-id= : Voiceflow project id (24-char hex)}
        {--api-key= : VF.DM.* Dialog Manager key}
        {--workspace-key= : Optional VF.WS.* workspace key for KB/analytics}
        {--environment=main : Environment alias inside the project}
        {--notes= : Free-form notes for the operator audit log}';

    protected $description = 'Add a pre-created Voiceflow project to the allocation pool.';

    public function handle(): int
    {
        $projectId = $this->option('project-id') ?: $this->ask('Voiceflow project id (24-char hex)');
        $apiKey = $this->option('api-key') ?: $this->secret('VF.DM.* API key');
        $workspaceKey = $this->option('workspace-key') ?: ($this->confirm('Add workspace key (for KB/analytics)?', false)
            ? $this->secret('VF.WS.* workspace key')
            : null);

        // Idempotency: the project_id is unique, so a re-run with the same
        // value bails cleanly rather than throwing a query exception.
        if (VoiceflowProjectPoolEntry::query()->where('voiceflow_project_id', $projectId)->exists()) {
            $this->warn("Pool entry for project '{$projectId}' already exists. Nothing to do.");

            return self::SUCCESS;
        }

        $entry = VoiceflowProjectPoolEntry::create([
            'voiceflow_project_id' => $projectId,
            'voiceflow_api_key' => $apiKey,
            'voiceflow_workspace_api_key' => $workspaceKey,
            'voiceflow_environment' => $this->option('environment') ?: 'main',
            'status' => VoiceflowProjectPoolEntry::STATUS_AVAILABLE,
            'notes' => $this->option('notes'),
        ]);

        $this->info("Added pool entry #{$entry->id} (project {$projectId}).");

        return self::SUCCESS;
    }
}
