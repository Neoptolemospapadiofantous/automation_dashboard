<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase J — managed-mode agents.
     *
     * `byok` (default, existing behaviour): user supplies their own Voiceflow
     * key + project_id. We host nothing on Voiceflow's side.
     *
     * `managed`: we own one master Voiceflow project + template environment.
     * On signup we clone the template via Voiceflow's Project API to mint a
     * fresh per-tenant environment. The agent row stores only the cloned
     * environment id — auth + project_id come from .env at request time.
     *
     * Defaulting existing rows to 'byok' preserves all current behaviour.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Historical default frozen as a literal — migrations must never
            // depend on model constants (the constant was removed when BYOK
            // left the codebase).
            $table->string('mode')->default('byok')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
