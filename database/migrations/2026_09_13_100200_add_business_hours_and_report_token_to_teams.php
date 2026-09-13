<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * business_hours: when a team is reachable for a human handoff (JSON —
 * see App\Support\BusinessHours). Null = always open, which is today's
 * behaviour: every escalation rings the phone whatever the hour.
 *
 * report_token: the unguessable key of the team's shareable weekly report
 * page (/report/{token}). Null = sharing off. Rotating it retires the old
 * link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->json('business_hours')->nullable()->after('profile');
            $table->string('report_token', 64)->nullable()->unique()->after('business_hours');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropUnique(['report_token']);
            $table->dropColumn(['business_hours', 'report_token']);
        });
    }
};
