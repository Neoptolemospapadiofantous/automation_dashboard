<?php

namespace App\Billing;

/**
 * Top-up credit pack catalog. Single source of truth for what's on offer,
 * what each pack costs, and how many credits it grants.
 *
 * INVARIANT (asserted by BillingInvariantsTest): every pack's $/credit is
 * STRICTLY worse than Operator's monthly rate. That is the upgrade-pressure
 * mechanism — users who consistently top up save money by upgrading — and
 * the margin floor: tier credit prices are calibrated against Operator's
 * $0.01596/credit, so any pack below it can turn tiers margin-negative
 * (the 2026-06-11 pricing audit caught exactly that).
 *
 *   Starter      $99/mo  →  2,500 credits = $0.0396/credit
 *   Operator     $399/mo → 25,000 credits = $0.01596/credit  ← floor
 *   Small pack   $29     →  1,000 credits = $0.0290/credit
 *   Medium pack  $119    →  5,000 credits = $0.0238/credit
 *   Large pack   $399    → 20,000 credits = $0.0200/credit
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

    public function priceUsd(): int
    {
        return match ($this) {
            self::Small => 29,
            self::Medium => 119,
            self::Large => 399,
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
     * @return array<int, array{id: string, label: string, credits: int, price_usd: int}>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $p) => [
            'id' => $p->value,
            'label' => $p->label(),
            'credits' => $p->credits(),
            'price_usd' => $p->priceUsd(),
        ], self::cases());
    }
}
