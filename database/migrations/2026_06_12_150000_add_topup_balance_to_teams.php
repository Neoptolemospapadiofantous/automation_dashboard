<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-bucket credits (policy decision 2026-06-12): the monthly allowance
 * (credit_balance) hard-resets at renewal as before, but PURCHASED
 * top-up credits now live in their own bucket and roll over until spent.
 * Consumption drains the monthly bucket first, so paid credits are the
 * last to go. Previously a renewal silently wiped unused top-ups the
 * customer had paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedBigInteger('topup_balance')->default(0)->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('topup_balance');
        });
    }
};
