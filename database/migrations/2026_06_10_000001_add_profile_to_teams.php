<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding profile capture. Stores the answers from the wizard's
 * intro page — industry, team size, primary use case — so:
 *   1. Marketing can segment by industry / use case
 *   2. We can tailor in-app prompts and example flows
 *   3. Sales can prioritize Custom-tier conversations by company shape
 *
 * Schema: free-form JSON. The migration is intentionally permissive —
 * adding/removing fields in the wizard doesn't require a new migration.
 * Frontend validates the enum values; backend just stores what comes in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->json('profile')->nullable()->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('profile');
        });
    }
};
