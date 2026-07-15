<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small analytics/ops columns shipped together:
 *
 *  - leads.follow_up_nudged_at — the stale-lead reminder marks a lead once
 *    it has nudged the assignee/owner, so the daily command never nags
 *    twice about the same lead.
 *  - runtime_usage.canned_turns / kb_hits / low_confidence_turns — per-day
 *    counters behind the customer-facing deflection, KB-hit and escalation
 *    quality tiles. Written alongside the existing turns/tokens rollup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('follow_up_nudged_at')->nullable()->after('last_contacted_at');
        });

        Schema::table('runtime_usage', function (Blueprint $table) {
            $table->unsignedInteger('canned_turns')->default(0)->after('tokens_out');
            $table->unsignedInteger('kb_hits')->default(0)->after('canned_turns');
            $table->unsignedInteger('low_confidence_turns')->default(0)->after('kb_hits');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('follow_up_nudged_at');
        });

        Schema::table('runtime_usage', function (Blueprint $table) {
            $table->dropColumn(['canned_turns', 'kb_hits', 'low_confidence_turns']);
        });
    }
};
