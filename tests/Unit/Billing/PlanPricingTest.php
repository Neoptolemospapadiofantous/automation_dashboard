<?php

namespace Tests\Unit\Billing;

use App\Billing\BillingCycle;
use App\Billing\Plan;
use Tests\TestCase;

/**
 * Pure Plan resolution: Stripe price-id mapping (both directions) and the
 * annual-equivalent monthly math. Extends Tests\TestCase only for config()
 * access; no DB. The Stripe checkout flows that consume these live in
 * tests/Feature/AnnualBillingTest.php.
 */
class PlanPricingTest extends TestCase
{
    public function test_plan_returns_annual_price_id_when_configured(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => 'price_starter_annual',
        ]);

        $this->assertSame('price_starter_monthly', Plan::Free->stripePriceId(BillingCycle::Monthly));
        $this->assertSame('price_starter_annual', Plan::Free->stripePriceId(BillingCycle::Annual));
    }

    public function test_plan_returns_null_annual_when_unconfigured(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => null,
        ]);

        $this->assertSame('price_starter_monthly', Plan::Free->stripePriceId(BillingCycle::Monthly));
        $this->assertNull(Plan::Free->stripePriceId(BillingCycle::Annual));
    }

    public function test_from_stripe_price_id_recognizes_annual(): void
    {
        config([
            'billing.stripe_price.operator' => 'price_operator_monthly',
            'billing.stripe_price.operator_annual' => 'price_operator_annual',
        ]);

        $this->assertSame(Plan::Pro, Plan::fromStripePriceId('price_operator_monthly'));
        $this->assertSame(Plan::Pro, Plan::fromStripePriceId('price_operator_annual'));
    }

    public function test_annual_equivalent_monthly_applies_savings(): void
    {
        // Starter is €99/mo; 17% off → ~€82/mo equivalent.
        $this->assertSame(82, Plan::Free->annualEquivalentMonthlyEur());
        // Operator is €399/mo; 17% off → ~€331/mo equivalent.
        $this->assertSame(331, Plan::Pro->annualEquivalentMonthlyEur());
        // Business has no monthly price → null.
        $this->assertNull(Plan::Business->annualEquivalentMonthlyEur());
    }
}
