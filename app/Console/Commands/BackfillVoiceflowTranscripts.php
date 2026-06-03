<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\ConversationRecorder;
use App\Services\VoiceflowService;
use Illuminate\Console\Command;

/**
 * Back-fill conversations that happened in Voiceflow (preview/widget, or before
 * local storage existed) into the local store via the Transcript API.
 *
 *   php artisan voiceflow:backfill --team=1 --take=50
 *
 * Idempotent: conversations are keyed on voiceflow_transcript_id, so re-running
 * tops up new transcripts without duplicating existing ones.
 */
class BackfillVoiceflowTranscripts extends Command
{
    protected $signature = 'voiceflow:backfill
        {--team= : The team id to attach the conversations to (required if more than one team)}
        {--take=50 : How many recent transcripts to pull (max 100)}
        {--skip=0 : Pagination offset}';

    protected $description = 'Import Voiceflow transcripts into the local conversation store.';

    public function handle(VoiceflowService $voiceflow, ConversationRecorder $recorder): int
    {
        if (! $voiceflow->isConfigured()) {
            $this->error('Voiceflow is not configured (set VOICEFLOW_API_KEY and VOICEFLOW_PROJECT_ID).');

            return self::FAILURE;
        }

        $team = $this->resolveTeam();
        if (! $team) {
            return self::FAILURE;
        }

        $take = min(100, max(1, (int) $this->option('take')));
        $skip = max(0, (int) $this->option('skip'));

        $this->info("Searching Voiceflow transcripts (take={$take}, skip={$skip})…");

        try {
            $transcripts = $voiceflow->searchTranscripts($take, $skip);
        } catch (\Throwable $e) {
            $this->error('Transcript search failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($transcripts === []) {
            $this->info('No transcripts found.');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($transcripts as $meta) {
            $transcriptId = $meta['id'] ?? null;
            $userId = $meta['userID'] ?? $meta['sessionID'] ?? $transcriptId;
            if (! $transcriptId) {
                continue;
            }

            // Already imported? Skip.
            if (Conversation::where('team_id', $team->id)->where('voiceflow_transcript_id', $transcriptId)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $transcript = $voiceflow->getTranscript($transcriptId);
            } catch (\Throwable $e) {
                $this->warn("  Could not fetch transcript {$transcriptId}: ".$e->getMessage());

                continue;
            }

            if (! $transcript) {
                continue;
            }

            $messages = $voiceflow->transcriptMessages($transcript);
            if ($messages === []) {
                $skipped++;

                continue;
            }

            // Stamp the team's current agent on backfilled rows so Phase G
            // scoping picks them up. Backfill that pre-dates multi-tenancy
            // would otherwise land with agent_id = NULL.
            $conversation = $recorder->resolve(
                teamId: $team->id,
                voiceflowUserId: (string) $userId,
                leadId: null,
                channel: 'voiceflow',
                agentId: $team->current_agent_id,
            );
            $conversation->forceFill([
                'voiceflow_transcript_id' => $transcriptId,
                'status' => $meta['endedAt'] ?? null ? 'ended' : 'active',
                'ended_at' => $meta['endedAt'] ?? null,
            ])->save();

            foreach ($messages as $m) {
                $recorder->record($conversation, $m['role'], $m['text'], $m['trace_type']);
            }

            $imported++;
            $this->line("  Imported {$transcriptId} (".count($messages).' messages)');
        }

        $this->info("Done. Imported {$imported}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    protected function resolveTeam(): ?Team
    {
        if ($id = $this->option('team')) {
            $team = Team::find($id);
            if (! $team) {
                $this->error("Team {$id} not found.");
            }

            return $team;
        }

        $teams = Team::query()->limit(2)->get();
        if ($teams->count() === 1) {
            return $teams->first();
        }

        $this->error('Multiple teams exist — specify which with --team=ID.');

        return null;
    }
}
