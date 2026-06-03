<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the current_team_id pattern from Jetstream: the user picks
     * one agent at a time per team, and every page reads from that agent.
     *
     * Nullable on purpose — fresh teams have no agent until the onboarding
     * wizard creates one. Pages render an empty-state CTA in that case.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('current_agent_id')
                ->nullable()
                ->after('user_id')
                ->constrained('agents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['current_agent_id']);
            $table->dropColumn('current_agent_id');
        });
    }
};
