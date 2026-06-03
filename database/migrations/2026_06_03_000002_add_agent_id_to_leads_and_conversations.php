<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-row agent scoping. Nullable so the next migration (backfill) can
     * populate values for existing rows without violating constraints.
     * Pages filter by agent_id; rows with NULL show up nowhere until the
     * backfill assigns them to a team default.
     */
    public function up(): void
    {
        foreach (['leads', 'conversations', 'messages', 'lead_assignments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('agent_id')
                    ->nullable()
                    ->after('team_id')
                    ->constrained('agents')
                    ->nullOnDelete();
                $t->index(['agent_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['leads', 'conversations', 'messages', 'lead_assignments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['agent_id']);
                $t->dropIndex([0 => "{$t->getTable()}_agent_id_index"]);
                $t->dropColumn('agent_id');
            });
        }
    }
};
