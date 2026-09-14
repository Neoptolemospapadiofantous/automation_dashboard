<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 2026-09-15 two-plan repricing: Starter €19.99 (old Growth's
 * entitlements) and Operator €39.99, plus Custom. The Growth enum case is
 * gone, so any row still carrying 'growth' would break Plan::from() — fold
 * it into Starter, which now IS the old Growth. Prod carries zero such
 * rows (verified 2026-09-15); this guards local and test databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('teams')->where('plan', 'growth')->update(['plan' => 'starter']);
    }

    public function down(): void
    {
        // One-way: 'growth' no longer exists as a plan.
    }
};
