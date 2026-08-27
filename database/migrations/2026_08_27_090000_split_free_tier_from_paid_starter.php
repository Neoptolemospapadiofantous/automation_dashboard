<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-27 competitive repricing: `Plan::Free` used to do double duty — it
 * was BOTH the default state of an unsubscribed team AND the paid €99
 * "Starter" tier. The new ladder gives Free its own identity (€0, 100
 * credits) and introduces `starter` (€19) + `growth` (€99) as real rungs.
 *
 * Any team that had actually PAID for the old Starter is sitting on
 * plan='free' with a live Stripe subscription. Its entitlements (1 agent,
 * 2,500 credits) are identical to the new Starter rung, so it moves there.
 * Stripe keeps billing it at its original Price until someone deliberately
 * migrates the subscription — i.e. paying customers are grandfathered, which
 * is the intended behaviour.
 *
 * Teams on plan='free' WITHOUT a live subscription are genuinely unsubscribed
 * and are left alone — they simply become the new Free tier.
 */
return new class extends Migration
{
    /** Stripe statuses that mean "this team is really paying us". */
    private const LIVE = ['active', 'trialing', 'past_due'];

    public function up(): void
    {
        DB::table('teams')
            ->where('plan', 'free')
            ->whereIn('stripe_subscription_status', self::LIVE)
            ->update(['plan' => 'starter']);
    }

    public function down(): void
    {
        DB::table('teams')
            ->where('plan', 'starter')
            ->update(['plan' => 'free']);
    }
};
