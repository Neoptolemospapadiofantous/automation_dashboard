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
 * NOTE on enum case names: `free`/`pro`/`business` keep their original string
 * values because the `teams.plan` column persists them — renaming would need a
 * data migration. `starter`/`growth` are new rungs added in the 2026-08-27
 * competitive repricing and carry their own values.
 *
 * Offer tiers (aligned with flowstack.run/pricing as of 2026-09-15 — the
 * two-plan repricing; founder call, "remove the 4 packages, make them 2"):
 *   - Starter  (€19.99/mo) — up to 5 agents, 10,000 credits, own key allowed
 *   - Operator (€39.99/mo) — up to 5 agents, 25,000 credits, best €/credit
 *   - Custom   (scoped 4-6 week project) — bespoke flows, custom integrations
 *
 * Free is NOT a tier any more (founder call 2026-09-15, reversing 2026-08-27):
 * it is the UNSUBSCRIBED default state — 0 credits, nothing granted, nothing
 * sold. The enum case stays because teams.plan persists 'free' for every team
 * that has not subscribed. Growth was folded into Starter in the same call;
 * a data migration maps any 'growth' row to 'starter'.
 *
 * Pricing is EUR throughout — we sell in Europe.
 *
 * REPRICED 2026-08-27, twice. Started at Starter €99 / Operator €399 with no
 * free tier — the most expensive entry point in the category with the fewest
 * credits, against rivals whose modal entry is €19-45 and who all ship a free
 * tier. Founder direction was then to go as cheap as the economics allow, with
 * a hard ceiling of **under $49 USD on the top plan** (€39 clears it at any
 * EUR/USD rate up to 1.25).
 *
 * Both cuts were unblocked by the SAME lever: the runtime tier multipliers in
 * config/runtime.php. They had drifted far below the token rates they are
 * supposed to track (`haiku` billed 1 credit/message while costing 20x what
 * `gpt`/nano does at the same 1 credit), which pinned the margin floor at
 * €0.01333/credit and capped any cut at Operator €339. Re-aligning them to the
 * real rates dropped the floor to €0.00156. See
 * tests/Unit/Billing/PricingInvariantsTest.php — it DERIVES the floor from
 * those rates, so it will tell you the true limit rather than guessing.
 *
 * Credits are sized so a typical team comfortably stays inside its
 * monthly allotment at normal usage; top-up packs cover the spike weeks.
 *
 * There is no trial either — do not add Stripe Checkout `trial_period_days`
 * to subscription sessions. The free door into the product is the free
 * 30-minute call, not a plan.
 */
enum Plan: string
{
    case Free = 'free';
    case Starter = 'starter';
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
            // Unsubscribed teams receive nothing — subscribing is the door.
            self::Free => 0,
            self::Starter => 10_000,
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
            // An unsubscribed team keeps the one agent onboarding created.
            self::Free => 1,
            self::Starter, self::Pro => 5,
            self::Business => PHP_INT_MAX,
        };
    }

    /**
     * Monthly recurring price in EUR. A float since the 2026-09-15 repricing
     * (psychological .99 pricing); format with two decimals in display code.
     * Null on Custom — that tier is project-based (scoped 4-6 week build)
     * rather than recurring SaaS.
     */
    public function priceEur(): ?float
    {
        return match ($this) {
            self::Free => 0.0,
            self::Starter => 19.99,
            self::Pro => 39.99,
            self::Business => null,
        };
    }

    /**
     * Position on the self-serve ladder, low to high. Drives upgrade vs
     * downgrade decisions — which in turn decide whether Stripe invoices the
     * difference immediately and whether the credit allowance may move.
     *
     * Custom sits outside the ladder (it is negotiated, never self-served),
     * so it ranks above everything and is refused by the switch flow.
     */
    /**
     * May this plan supply its own provider API key (bring-your-own-key)?
     *
     * Operator and above. Gating on the enum case rather than a label keeps
     * this correct if the customer-facing name changes again — Pro IS the
     * rung sold as "Operator".
     *
     * The gate is enforced at resolution time too, not just in the UI, so a
     * downgrade stops BYOK immediately rather than leaving a stored key
     * quietly powering free traffic.
     */
    /**
     * Bring-your-own-key is available on every paid plan (since the
     * 2026-09-15 two-plan repricing folded Growth — the old BYOK floor —
     * into Starter). Premium engines are BYOK-only, so this is also the
     * gate on using any model other than Flowstack Core.
     */
    public function allowsOwnKey(): bool
    {
        return match ($this) {
            self::Starter, self::Pro, self::Business => true,
            default => false,
        };
    }

    /**
     * Whether the plan includes a free website build — the brochure-style
     * engagement at flowstack.run/website-build, up to about six pages with
     * the chat installed. A shop or portal is still quoted as its own build.
     *
     * ANNUAL ONLY, and that is the whole point: a monthly Operator subscriber
     * does not qualify. Every surface that shows this must name the annual
     * condition, because the same plan card renders for both cycles.
     */
    public function includesWebsiteBuildOnAnnual(): bool
    {
        return $this === self::Pro;
    }

    /**
     * Monthly chat-message ceiling for turns that run on the team's OWN key.
     *
     * A BYOK turn spends no credits, so the credit balance stops bounding
     * usage — this replaces it. Deliberately the same number as the plan's
     * credit allotment so the customer story stays one number ("25,000
     * messages"), not a second currency to reason about.
     *
     * 0 = BYOK not available on this plan.
     */
    public function monthlyMessageCap(): int
    {
        return match ($this) {
            // Mirrors each plan's credit allotment, so the customer story
            // stays one number whichever way their turns are paid for.
            self::Starter => 10_000,
            self::Pro => 25_000,
            self::Business => PHP_INT_MAX,
            default => 0,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Starter => 1,
            self::Pro => 2,
            self::Business => 3,
        };
    }

    /**
     * Is moving to $target a move UP the ladder from this plan?
     */
    public function isUpgradeTo(self $target): bool
    {
        return $target->rank() > $this->rank();
    }

    /**
     * Whether this plan is a paid, Stripe-billed subscription. Free is
     * self-serve with no Stripe object at all; Custom is invoiced
     * out-of-band. Used by the pricing invariants (a €0 plan can't be a
     * "cheapest credit source") and the renewal safety net.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Starter, self::Pro => true,
            self::Free, self::Business => false,
        };
    }

    /**
     * Whether the plan can buy top-up credit packs when its monthly
     * allotment runs out. Every paid recurring tier can; Free cannot (the
     * cap is the point — topping up is the upgrade prompt), and Custom is
     * project-based and handled out-of-band.
     *
     * Pack pricing is intentionally unfavourable at low volume (see
     * TopUpPack) so heavy users still feel pressure to upgrade rather
     * than top-up forever — but the door stays open for someone who
     * just needs a one-off bump to finish the month.
     */
    public function allowsTopUps(): bool
    {
        return $this->isPaid();
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
            [self::Starter, BillingCycle::Monthly] => config('billing.stripe_price.starter'),
            [self::Starter, BillingCycle::Annual] => config('billing.stripe_price.starter_annual'),
            [self::Pro, BillingCycle::Monthly] => config('billing.stripe_price.operator'),
            [self::Pro, BillingCycle::Annual] => config('billing.stripe_price.operator_annual'),
            default => null, // Free has no Stripe object; Custom is project-based
        };

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The actual yearly charge in EUR — what Stripe bills on the annual
     * Price, NOT a derived figure. Roughly "two months free" against 12×
     * monthly, rounded to a clean number.
     *
     * This is deliberately its own source of truth. Deriving the yearly
     * total from a percentage produced a real misstatement: the Billing UI
     * rendered annualEquivalentMonthlyEur() × 12, which for Starter came to
     * €84/yr while Stripe actually charged €90. Keep this in step with the
     * annual Stripe Prices (STRIPE_PRICE_*_ANNUAL) — PlanPricingTest asserts
     * the discount they imply stays sane.
     */
    public function annualPriceEur(): ?float
    {
        return match ($this) {
            // 20% off 12x the monthly price (founder 2026-09-15): 239.88 → 191.90,
            // 479.88 → 383.90. Kept as its own source of truth, in step with the
            // annual Stripe Prices; annualSavingsPct() derives the 20 from it.
            self::Free => 0.0,
            self::Starter => 191.90,
            self::Pro => 383.90,
            self::Business => null,
        };
    }

    /**
     * Annual discount as a whole percent, DERIVED from the two real prices
     * rather than asserted — so it can never disagree with what we charge.
     */
    public function annualSavingsPct(): int
    {
        $monthly = $this->priceEur();
        $annual = $this->annualPriceEur();
        if ($monthly === null || $annual === null || $monthly === 0.0) {
            return 0;
        }

        return (int) round((1 - $annual / ($monthly * 12)) * 100);
    }

    /**
     * The "equivalent monthly" price when paying annually — the real yearly
     * charge spread over 12. UI shows this alongside the actual monthly for
     * the side-by-side comparison. Null when there is no price (Custom).
     */
    public function annualEquivalentMonthlyEur(): ?int
    {
        $annual = $this->annualPriceEur();

        return $annual === null ? null : (int) round($annual / 12);
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Starter => 'Starter',
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
