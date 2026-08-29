<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ceiling that replaces credits for BYOK turns.
     *
     * A team using its own key spends no credits, so the credit balance
     * stops being a limit — these two columns are what bounds usage
     * instead. Counting rather than deriving from `messages` keeps the
     * hot path a single atomic increment.
     *
     * `byok_period_start` is the anchor: when it is older than a month the
     * counter resets on next use, so the window follows the billing period
     * without needing a scheduled job.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedInteger('byok_messages_used')->default(0)->after('topup_balance');
            $table->timestamp('byok_period_start')->nullable()->after('byok_messages_used');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['byok_messages_used', 'byok_period_start']);
        });
    }
};
