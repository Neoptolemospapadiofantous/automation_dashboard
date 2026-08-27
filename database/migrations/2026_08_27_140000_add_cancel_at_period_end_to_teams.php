<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve cancellation: a customer cancelling in-app sets Stripe's
 * cancel_at_period_end rather than deleting the subscription, so they keep
 * what they paid for until the period ends and can change their mind.
 *
 * Mirrored locally so the Billing page can render "cancels on <date>" and
 * offer Resume without an API round-trip on every page load. Stripe stays
 * the source of truth; customer.subscription.updated keeps this in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->boolean('stripe_cancel_at_period_end')
                ->default(false)
                ->after('stripe_subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('stripe_cancel_at_period_end');
        });
    }
};
