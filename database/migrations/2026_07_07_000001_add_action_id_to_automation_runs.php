<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable per-action identity for the audit log. `action` is the display name
 * at dispatch time and splits history when an operator renames an action;
 * `action_id` carries the ULID minted in the action's config entry, so runs
 * of the same action correlate across renames. Nullable: rows written before
 * ids existed (or from configs not yet re-saved) have no id to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->string('action_id', 26)->nullable()->after('action');

            $table->index(['agent_id', 'action_id']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropIndex(['agent_id', 'action_id']);
            $table->dropColumn('action_id');
        });
    }
};
