<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $t): void {
            // Tracks which credit-burn thresholds have already fired this
            // billing period, so we never warn twice for the same crossing.
            // Stored as a JSON array of stringified percentages: ["50","80"].
            // Cleared by grantMonthlyRenewal() and re-evaluated by grantTopUp().
            $t->json('alert_thresholds_fired')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $t): void {
            $t->dropColumn('alert_thresholds_fired');
        });
    }
};
