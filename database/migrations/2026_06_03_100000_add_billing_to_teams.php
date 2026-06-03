<?php

use App\Billing\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teams carry the billing relationship. We don't put plan_id on User
     * because in a team-based app, billing happens at the team level
     * (one team, one subscription, many users).
     *
     * credit_balance is the live counter checked + decremented per message.
     * credits_renewed_at marks the most recent monthly reset so we can fire
     * a top-up when a Stripe invoice is paid without double-granting.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('plan')->default(Plan::Free->value)->after('current_agent_id');
            $table->unsignedInteger('credit_balance')->default(0)->after('plan');
            $table->timestamp('credits_renewed_at')->nullable()->after('credit_balance');
        });

        // Seed every existing team with the Free plan's credit allotment so
        // the upgrade path is the user's first action, not a hard-block on
        // existing usage. Done in raw SQL since the model isn't aware yet.
        DB::table('teams')->whereNull('credits_renewed_at')->update([
            'plan' => Plan::Free->value,
            'credit_balance' => Plan::Free->monthlyCredits(),
            'credits_renewed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['plan', 'credit_balance', 'credits_renewed_at']);
        });
    }
};
