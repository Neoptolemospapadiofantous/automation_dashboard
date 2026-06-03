<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-shot backfill: any team that existed before multi-tenancy gets one
 * Agent row using the legacy .env credentials, becomes its current_agent_id,
 * and all of that team's leads/conversations/messages/lead_assignments get
 * agent_id set.
 *
 * Idempotent: a team that already has an agent is left alone. Safe to re-run.
 *
 * The legacy .env values may be empty (e.g. when this migration runs in CI
 * before keys are configured). We still create the row so the team has SOMETHING
 * to point existing rows at; the onboarding wizard will catch the empty creds
 * on first login and force the user to fill them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = DB::table('teams')->select('id', 'name', 'current_agent_id')->get();

        foreach ($teams as $team) {
            if ($team->current_agent_id) {
                continue;
            }

            $existingAgent = DB::table('agents')->where('team_id', $team->id)->first();

            if ($existingAgent) {
                $agentId = $existingAgent->id;
            } else {
                $agentId = DB::table('agents')->insertGetId([
                    'team_id' => $team->id,
                    'name' => 'Default agent',
                    'slug' => 'team-'.$team->id.'-'.Str::lower(Str::random(8)),
                    'voiceflow_api_key' => $this->encryptOrNull(config('services.voiceflow.api_key')),
                    'voiceflow_project_id' => config('services.voiceflow.project_id'),
                    'voiceflow_environment' => config('services.voiceflow.environment', 'main'),
                    'voiceflow_workspace_api_key' => null,
                    // Reuse the global webhook secret so any existing Voiceflow
                    // agent's Custom Action keeps working with the new per-agent
                    // URL. If the global secret is empty, generate a new one so
                    // the column NOT-NULL constraint holds.
                    'webhook_secret' => config('services.voiceflow.webhook_secret') ?: Str::random(40),
                    'status' => config('services.voiceflow.api_key') ? 'active' : 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('teams')->where('id', $team->id)->update(['current_agent_id' => $agentId]);

            // Assign the team's existing rows to its default agent. Safe to
            // run repeatedly because we only touch rows that are still NULL.
            foreach (['leads', 'conversations', 'messages', 'lead_assignments'] as $table) {
                DB::table($table)
                    ->where('team_id', $team->id)
                    ->whereNull('agent_id')
                    ->update(['agent_id' => $agentId]);
            }
        }
    }

    public function down(): void
    {
        // Best-effort: null out current_agent_id and the per-row agent_id so
        // the FKs in 000000 / 000002 can be dropped. We don't delete the
        // agent rows themselves — losing user credentials on rollback would
        // be unrecoverable. Agents created by this migration are identifiable
        // by the 'team-{id}-' slug prefix if a true rollback is needed.
        DB::table('teams')->update(['current_agent_id' => null]);

        foreach (['leads', 'conversations', 'messages', 'lead_assignments'] as $table) {
            DB::table($table)->update(['agent_id' => null]);
        }
    }

    protected function encryptOrNull(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return encrypt($value);
    }
};
