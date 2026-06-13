<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the legacy voiceflow_* columns to their native names. These columns
 * outlived the Voiceflow engine (dropped in 2026_06_11_100000) — they hold our
 * own runtime's visitor/session/transcript identifiers, so the naming now
 * reflects that. "Voiceflow" survives only in docs/ as historical reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('voiceflow_user_id', 'visitor_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->renameColumn('voiceflow_user_id', 'visitor_id');
            $table->renameColumn('voiceflow_session_key', 'session_key');
            $table->renameColumn('voiceflow_transcript_id', 'transcript_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('visitor_id', 'voiceflow_user_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->renameColumn('visitor_id', 'voiceflow_user_id');
            $table->renameColumn('session_key', 'voiceflow_session_key');
            $table->renameColumn('transcript_id', 'voiceflow_transcript_id');
        });
    }
};
