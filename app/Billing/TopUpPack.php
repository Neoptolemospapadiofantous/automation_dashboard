<?php

namespace App\Billing;

/**
 * Top-up credit pack catalog. Single source of truth for what's on offer,
 * what each pack costs, and how many credits it grants.
 *
 * Pricing is EUR throughout — we sell in Europe.
 *
 * INVARIANT (asserted by PricingInvariantsTest): every pack's €/credit is
 * STRICTLY worse than Operator's monthly rate. That is the upgrade-pressure
 * mechanism — users who consistently top up save money by upgrading — and
 * the margin floor: tier credit prices are calibrated against Operator's
 * €/credit, so any pack below it can turn tiers margin-negative
 * (the 2026-06-11 pricing audit caught exactly that).
 *
 * Ladder as of the 2026-08-27 repricing (Free is excluded from the floor
 * maths — a €0 rung has no €/credit, and its 100-credit cap is what bounds
 * the exposure):
 *
 *   Starter      €9/mo   →  2,500 credits = €0.00360/credit
 *   Growth       €19/mo  → 10,000 credits = €0.00190/credit
 *   Operator     €39/mo  → 25,000 credits = €0.00156/credit  ← floor
 *   Small pack   €5      →  1,000 credits = €0.00500/credit
 *   Medium pack  €15     →  5,000 credits = €0.00300/credit
 *   Large pack   €40     → 20,000 credits = €0.00200/credit
 *
 * When Phase H (Stripe Checkout) ships, the stripePriceId() values map
 * to one-off SKU price ids. Until then, the BillingController instant-
 * grants in dev mode with a flag in the audit meta.
 */
enum TopUpPack: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function credits(): int
    {
        return match ($this) {
            self::Small => 1_000,
            self::Medium => 5_000,
            self::Large => 20_000,
        };
    }

    public function priceEur(): int
    {
        return match ($this) {
            self::Small => 5,
            self::Medium => 15,
            self::Large => 40,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small top-up',
            self::Medium => 'Medium top-up',
            self::Large => 'Large top-up',
        };
    }

    public function stripePriceId(): ?string
    {
        return match ($this) {
            self::Small => config('billing.stripe_price.topup_small'),
            self::Medium => config('billing.stripe_price.topup_medium'),
            self::Large => config('billing.stripe_price.topup_large'),
        };
    }

    /**
     * @return array<int, array{id: string, label: string, credits: int, price_eur: int}>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $p) => [
            'id' => $p->value,
            'label' => $p->label(),
            'credits' => $p->credits(),
            'price_eur' => $p->priceEur(),
        ], self::cases());
    }
}
