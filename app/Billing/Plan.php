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
            self::Free => 100,
            self::Pro => 5_000,
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
     * Whether the plan can buy top-up credit packs when its monthly
     * allotment runs out. Free is locked to hard cap to push upgrades.
     */
    public function allowsTopUps(): bool
    {
        return $this !== self::Free;
    }

    /**
     * Stripe price id used to identify the plan in Cashier webhooks. Set
     * via env so test/staging/prod each map to their own Stripe products
     * without code changes.
     */
    public function stripePriceId(): ?string
    {
        return match ($this) {
            self::Free => null,
            self::Pro => config('billing.stripe_price.pro'),
            self::Business => config('billing.stripe_price.business'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Business => 'Business',
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
