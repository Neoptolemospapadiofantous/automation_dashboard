<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LLM-format conversation history per runtime session.
 *
 * This is DISTINCT from the conversations/messages tables: those hold the
 * display transcript for the dashboard; this holds the exact Anthropic
 * message array (including tool_use / tool_result content blocks) needed
 * to replay context into the next completion call. Trimmed to
 * config('runtime.session.history_limit') entries by SessionManager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_sessions', function (Blueprint $table): void {
            $table->json('history')->nullable()->after('variables');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_sessions', function (Blueprint $table): void {
            $table->dropColumn('history');
        });
    }
};
