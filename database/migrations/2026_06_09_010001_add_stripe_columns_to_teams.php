<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $t): void {
            // Stripe Customer ID — created lazily on first checkout/subscribe.
            // One per team. Indexed for the webhook lookup path (Stripe
            // sends us cus_*, we need to find the team).
            $t->string('stripe_customer_id')->nullable()->unique();

            // Active subscription ID — null until subscribed; set when
            // checkout.session.completed fires, cleared when subscription.deleted.
            // Indexed so webhook reverse-lookup is O(1).
            $t->string('stripe_subscription_id')->nullable()->index();

            // Subscription status mirror — Stripe is the source of truth, but
            // we cache the last-known status here so the dashboard doesn't
            // hit the Stripe API to render the billing card. Valid values:
            // 'active' | 'past_due' | 'canceled' | 'incomplete' | 'trialing'.
            $t->string('stripe_subscription_status', 32)->nullable();

            // When the current paid period ends. Used to render the "next
            // renewal" / "ends on" date in the billing UI. Updated by the
            // invoice.paid webhook.
            $t->timestamp('stripe_current_period_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $t): void {
            $t->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_subscription_status',
                'stripe_current_period_end',
            ]);
        });
    }
};
