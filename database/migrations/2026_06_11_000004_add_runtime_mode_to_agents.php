<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent runtime selector — the feature flag that decides which
 * conversational engine handles this agent's traffic.
 *
 *   voiceflow (default): the existing VoiceflowService path. All
 *     current agents start here; no behaviour change.
 *
 *   native: the new AgentRuntime path (built in Phases 2-8 of the
 *     runtime branch). Once Phase 8 lands, we'll start flipping
 *     specific test agents to 'native' to validate the migration
 *     before rolling forward to new signups.
 *
 * Stored as a string (not enum) so adding future runtimes (e.g.
 * 'openai_assistants', 'partner_x') is a no-migration change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->string('runtime_mode', 32)->default('voiceflow')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('runtime_mode');
        });
    }
};
