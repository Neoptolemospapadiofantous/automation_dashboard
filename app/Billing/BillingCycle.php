<?php

namespace App\Billing;

/**
 * Subscription billing cadence — monthly (default) or annual.
 *
 * Annual offers ~17% off — equivalent to paying for 10 months and getting
 * 12, which is the standard SaaS annual incentive. The actual percentage
 * comes from Stripe (the annual Price IDs are stored separately) — this
 * enum is just the cycle key.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Annual => 'Annual',
        };
    }
}
