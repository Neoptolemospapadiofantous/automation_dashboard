<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voiceflow_webhook_events')) {
            return;
        }

        Schema::table('voiceflow_webhook_events', function (Blueprint $t): void {
            // Pre-existing column declared as unsignedBigInteger().nullable().index()
            // without a constraint. Adding the FK + nullOnDelete so a team delete
            // doesn't orphan webhook rows. The agent_id FK already has the same
            // shape — this normalizes the pair.
            $t->foreign('team_id')
                ->references('id')
                ->on('teams')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('voiceflow_webhook_events')) {
            return;
        }

        Schema::table('voiceflow_webhook_events', function (Blueprint $t): void {
            $t->dropForeign(['team_id']);
        });
    }
};
