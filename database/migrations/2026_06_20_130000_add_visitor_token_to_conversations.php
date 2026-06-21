<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent embed-visitor identity. `visitor_id` is now per-chat-session (each
 * reset threads a fresh one so the runtime session + transcript start clean);
 * `visitor_token` is the stable browser identity that survives resets, letting
 * the widget show a returning visitor their own recent conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('visitor_token')->nullable()->after('visitor_id');
            $table->index(['team_id', 'visitor_token', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'visitor_token', 'started_at']);
            $table->dropColumn('visitor_token');
        });
    }
};
