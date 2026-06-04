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
 * Custom direction (see priceUsd() + label() + features); future Phase H
 * Stripe wiring can introduce new case names + migration as needed.
 *
 * Offer tiers (current):
 *   - Starter  ($19/mo)  — 1 agent, 1k credits, "try out the product"
 *   - Operator ($79/mo)  — up to 5 agents, 10k credits, top-ups enabled
 *   - Custom   (from $6k project) — bespoke agents + n8n ops + integrations
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    /**
     * Monthly credit allotment. 1 credit = 1 message (user OR agent direction).
     */
    public function monthlyCredits(): int
    {
        return match ($this) {
            self::Free => 1_000,
            self::Pro => 10_000,
            self::Business => 50_000,
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
     * Monthly recurring price in USD. Returned as int so display code
     * can format consistently. Null on Custom — that tier is project-based
     * (from $6k fixed-scope) rather than recurring SaaS.
     */
    public function priceUsd(): ?int
    {
        return match ($this) {
            self::Free => 19,
            self::Pro => 79,
            self::Business => null,
        };
    }

    /**
     * Whether the plan can buy top-up credit packs when its monthly
     * allotment runs out. Starter is locked to hard cap to push upgrades
     * to Operator; Custom is project-based and handled out-of-band.
     */
    public function allowsTopUps(): bool
    {
        return $this === self::Pro;
    }

    /**
     * Stripe price id used to identify the plan in Cashier webhooks. Set
     * via env so test/staging/prod each map to their own Stripe products
     * without code changes.
     */
    public function stripePriceId(): ?string
    {
        return match ($this) {
            self::Free => config('billing.stripe_price.starter'),
            self::Pro => config('billing.stripe_price.operator'),
            self::Business => null, // Custom is project-based, not Stripe-priced
        };
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
     * id, which plan does it belong to? Returns null if no match (Stripe
     * sent us a price we don't recognize → log + ignore).
     */
    public static function fromStripePriceId(string $priceId): ?self
    {
        foreach (self::cases() as $plan) {
            if ($plan->stripePriceId() === $priceId) {
                return $plan;
            }
        }

        return null;
    }
}
