<?php

namespace App\Billing;

/**
 * Top-up credit pack catalog. Single source of truth for what's on offer,
 * what each pack costs, and how many credits it grants.
 *
 * Pricing per credit is INTENTIONALLY worse than the Operator tier on the
 * small pack and only matches Operator on the large pack. This is the
 * upgrade-pressure mechanism: users who consistently top up will save
 * money by upgrading to Operator (or upsizing to Custom).
 *
 *   Starter        $19/mo → 1k credits   = $0.0190/credit
 *   Small pack     $10    → 1k credits   = $0.0100/credit
 *   Operator       $79/mo → 10k credits  = $0.0079/credit
 *   Medium pack    $39    → 5k credits   = $0.0078/credit
 *   Large pack     $129   → 20k credits  = $0.0065/credit
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
            self::Small => 10,
            self::Medium => 39,
            self::Large => 129,
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
