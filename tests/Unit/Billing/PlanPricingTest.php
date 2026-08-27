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

        $this->assertSame('price_starter_monthly', Plan::Starter->stripePriceId(BillingCycle::Monthly));
        $this->assertSame('price_starter_annual', Plan::Starter->stripePriceId(BillingCycle::Annual));
    }

    public function test_plan_returns_null_annual_when_unconfigured(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => null,
        ]);

        $this->assertSame('price_starter_monthly', Plan::Starter->stripePriceId(BillingCycle::Monthly));
        $this->assertNull(Plan::Starter->stripePriceId(BillingCycle::Annual));
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
        // Equivalent-monthly is the REAL yearly charge over 12, not a
        // percentage applied to the monthly price.
        $this->assertSame(8, Plan::Starter->annualEquivalentMonthlyEur());   // €90/yr
        $this->assertSame(16, Plan::Growth->annualEquivalentMonthlyEur());   // €190/yr
        $this->assertSame(33, Plan::Pro->annualEquivalentMonthlyEur());      // €390/yr
        // Free is €0 — the discount is a no-op, not null.
        $this->assertSame(0, Plan::Free->annualEquivalentMonthlyEur());
        // Business has no monthly price → null.
        $this->assertNull(Plan::Business->annualEquivalentMonthlyEur());
    }

    public function test_free_has_no_stripe_price_in_either_cycle(): void
    {
        // Free is the default state, not a purchasable SKU — a Stripe price
        // id here would let someone "check out" for €0 and confuse the
        // subscription webhook's plan resolution.
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => 'price_starter_annual',
        ]);

        $this->assertNull(Plan::Free->stripePriceId(BillingCycle::Monthly));
        $this->assertNull(Plan::Free->stripePriceId(BillingCycle::Annual));
    }

    public function test_from_stripe_price_id_distinguishes_growth_from_operator(): void
    {
        config([
            'billing.stripe_price.growth' => 'price_growth_monthly',
            'billing.stripe_price.operator' => 'price_operator_monthly',
        ]);

        $this->assertSame(Plan::Growth, Plan::fromStripePriceId('price_growth_monthly'));
        $this->assertSame(Plan::Pro, Plan::fromStripePriceId('price_operator_monthly'));
        $this->assertNull(Plan::fromStripePriceId('price_unknown'));
    }

    public function test_annual_price_is_a_real_discount_on_twelve_months(): void
    {
        // The yearly figure is its own source of truth (it must equal the
        // Stripe annual Price), so nothing derives it — which means nothing
        // stops a typo either. This is that guard: every paid rung must save
        // the buyer something real, but not so much that annual is a mistake.
        foreach ([Plan::Starter, Plan::Growth, Plan::Pro] as $plan) {
            $twelveMonths = $plan->priceEur() * 12;
            $annual = $plan->annualPriceEur();

            $this->assertLessThan(
                $twelveMonths,
                $annual,
                "{$plan->label()} annual (€{$annual}) is not cheaper than 12 months (€{$twelveMonths}).",
            );
            $this->assertGreaterThanOrEqual(10, $plan->annualSavingsPct(), "{$plan->label()} annual discount is too small to advertise.");
            $this->assertLessThanOrEqual(25, $plan->annualSavingsPct(), "{$plan->label()} annual discount looks like a pricing typo.");
        }

        // Free and Custom have nothing to discount.
        $this->assertSame(0, Plan::Free->annualPriceEur());
        $this->assertNull(Plan::Business->annualPriceEur());
    }
}
