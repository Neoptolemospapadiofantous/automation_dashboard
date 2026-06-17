<?php

namespace App\Billing;

/**
 * Subscription plans (and the Free fallback).
 *
 * Plans are hand-rolled in code rather than table-driven because the
 * pricing+limits are part of the product contract — changing them is a
 * deploy event, not a runtime config tweak. When this becomes a real
 * admin UI later, convert to a `plans` table with the same shape.
 *
 * stripePriceId is null on Free; for paid tiers it must be set via
 * .env (and then mapped via fromStripePriceId() when Stripe webhooks
 * fire). Credit grants happen on subscription_created and on each
 * invoice_paid (monthly renewal).
 */
/**
 * NOTE on enum case names: cases stayed `free`/`pro`/`business` because the
 * `teams.plan` column persists the string value — renaming would need a
 * data migration. The offer was rebranded in the Starter / Operator /
 * Custom direction (see priceEur() + label() + features); future Phase H
 * Stripe wiring can introduce new case names + migration as needed.
 *
 * Offer tiers (aligned with flowstack.run/pricing as of 2026-06-09):
 *   - Starter  (€99/mo)  — 1 agent, 2,500 credits, "try the product"
 *   - Operator (€399/mo) — up to 5 agents, 25,000 credits, top-ups enabled
 *   - Custom   (scoped 4-6 week project) — bespoke flows, custom integrations
 *
 * Pricing is EUR throughout — we sell in Europe.
 *
 * Credits are sized so a typical team comfortably stays inside its
 * monthly allotment at normal usage; top-up packs cover the spike weeks.
 *
 * No free trial — product decision 2026-06-09. The €99 Starter tier IS
 * the entry point; cancel anytime via the Stripe Billing Portal. Do
 * not add Stripe Checkout `trial_period_days` to subscription sessions.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    /**
     * Monthly credit allotment. credits meter messages (1× base, higher multipliers on smarter model tiers) (user OR agent direction).
     *
     * Custom returns 0: no auto-renewal grant. Custom is project-based —
     * credits are negotiated per engagement and granted manually by ops.
     * UI (HandleInertiaRequests + Billing/Index.vue) detects Custom and
     * shows "Custom — negotiated" instead of "0 / 0".
     */
    public function monthlyCredits(): int
    {
        return match ($this) {
            self::Free => 2_500,
            self::Pro => 25_000,
            self::Business => 0,
        };
    }

    /**
     * Max agents a team on this plan can own.
     */
    public function maxAgents(): int
    {
        return match ($this) {
            self::Free => 1,
            self::Pro => 5,
            self::Business => PHP_INT_MAX,
        };
    }

    /**
     * Monthly recurring price in EUR. Returned as int so display code
     * can format consistently. Null on Custom — that tier is project-based
     * (scoped 4-6 week build) rather than recurring SaaS.
     */
    public function priceEur(): ?int
    {
        return match ($this) {
            self::Free => 99,
            self::Pro => 399,
            self::Business => null,
        };
    }

    /**
     * Whether the plan can buy top-up credit packs when its monthly
     * allotment runs out. Both paid tiers can; Custom is project-based
     * and handled out-of-band.
     *
     * Pack pricing is intentionally unfavourable at low volume (see
     * TopUpPack) so heavy users still feel pressure to upgrade rather
     * than top-up forever — but the door stays open for someone who
     * just needs a one-off bump to finish the month.
     */
    public function allowsTopUps(): bool
    {
        return $this === self::Free || $this === self::Pro;
    }

    /**
     * Stripe price id for the given billing cycle. Set via env so
     * test/staging/prod each map to their own Stripe products without
     * code changes. Annual returns null when not configured — UI hides
     * the annual toggle in that case.
     */
    public function stripePriceId(BillingCycle $cycle = BillingCycle::Monthly): ?string
    {
        $value = match ([$this, $cycle]) {
            [self::Free, BillingCycle::Monthly] => config('billing.stripe_price.starter'),
            [self::Free, BillingCycle::Annual] => config('billing.stripe_price.starter_annual'),
            [self::Pro, BillingCycle::Monthly] => config('billing.stripe_price.operator'),
            [self::Pro, BillingCycle::Annual] => config('billing.stripe_price.operator_annual'),
            default => null, // Custom is project-based, not Stripe-priced
        };

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Annual price discount — the 12-month payment compared to 12×
     * monthly. Currently a hard-coded 17% ("get 2 months free"). When
     * Stripe products are configured with different ratios, read this
     * from the Stripe price object instead.
     */
    public function annualSavingsPct(): int
    {
        return 17;
    }

    /**
     * The "equivalent monthly" price when paying annually. UI shows this
     * alongside the actual monthly for the side-by-side comparison.
     * Null when no monthly price (Custom).
     */
    public function annualEquivalentMonthlyEur(): ?int
    {
        $monthly = $this->priceEur();
        if ($monthly === null) {
            return null;
        }

        return (int) round($monthly * (1 - $this->annualSavingsPct() / 100));
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Starter',
            self::Pro => 'Operator',
            self::Business => 'Custom',
        };
    }

    /**
     * Reverse lookup for Stripe webhook handlers — given a Stripe price
     * id, which plan does it belong to? Checks both monthly + annual
     * cycles. Returns null if no match (Stripe sent us a price we don't
     * recognize → log + ignore).
     */
    public static function fromStripePriceId(string $priceId): ?self
    {
        foreach (self::cases() as $plan) {
            foreach (BillingCycle::cases() as $cycle) {
                if ($plan->stripePriceId($cycle) === $priceId) {
                    return $plan;
                }
            }
        }

        return null;
    }
}
