<?php

namespace Tests\Unit\Billing;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use Tests\TestCase;

/**
 * Standing pricing invariants from the 2026-06-11 pricing audit
 * (docs/operations/pricing-audit.md). If any of these fail, someone
 * repriced plans/packs/tiers without re-running the margin math.
 *
 * Pure pricing logic — Plan/TopUpPack enum math plus the pricing config.
 * Extends Tests\TestCase only for config() access; it runs no queries, so
 * it stays off the RefreshDatabase path (that's why this lives in Unit, not
 * Feature). The DB/HTTP-bound billing flows remain in BillingInvariantsTest.
 */
class PricingInvariantsTest extends TestCase
{
    public function test_every_topup_pack_is_pricier_per_credit_than_operator(): void
    {
        // The upgrade-pressure mechanism AND the margin floor: tier credit
        // prices are calibrated against Operator's $/credit; any pack below
        // it can turn tiers margin-negative.
        $operatorPerCredit = Plan::Pro->priceEur() / Plan::Pro->monthlyCredits();

        foreach (TopUpPack::cases() as $pack) {
            $packPerCredit = $pack->priceEur() / $pack->credits();

            $this->assertGreaterThan(
                $operatorPerCredit,
                $packPerCredit,
                "{$pack->label()} (€{$packPerCredit}/credit) undercuts Operator (€{$operatorPerCredit}/credit) — margin-negative risk.",
            );
        }
    }

    public function test_custom_topup_rate_beats_the_operator_floor(): void
    {
        // The custom amount grants credits at billing.topup_custom.credits_per_eur.
        // Its €/credit MUST stay strictly above Operator's floor, same as the
        // fixed packs — otherwise a big custom buy goes margin-negative.
        $operatorPerCredit = Plan::Pro->priceEur() / Plan::Pro->monthlyCredits();
        $customPerCredit = 1 / (int) config('billing.topup_custom.credits_per_eur');

        $this->assertGreaterThan(
            $operatorPerCredit,
            $customPerCredit,
            "Custom top-up (€{$customPerCredit}/credit) undercuts Operator (€{$operatorPerCredit}/credit) — margin-negative risk.",
        );
    }

    public function test_every_tier_is_margin_positive_at_high_usage_on_the_cheapest_credits(): void
    {
        // HIGH scenario: 2 LLM calls × (8k in / 800 out) per visitor turn.
        // Worst revenue source = cheapest $/credit across plans and packs.
        // Embed bills (1 + replies) ≈ 2 × multiplier per turn.
        // PAID plans only. A €0 rung has no €/credit to speak of — including
        // Free here would make the "cheapest credit source" zero and fail by
        // construction. Free's exposure is bounded by its allotment cap
        // instead, which test_free_tier_allotment_stays_capped guards.
        $sources = [];
        foreach (Plan::cases() as $plan) {
            if ($plan->isPaid()) {
                $sources[] = $plan->priceEur() / $plan->monthlyCredits();
            }
        }
        foreach (TopUpPack::cases() as $pack) {
            $sources[] = $pack->priceEur() / $pack->credits();
        }
        $worstPerCredit = min($sources);

        foreach ((array) config('runtime.tiers') as $key => $tier) {
            $rates = (array) $tier['pricing_per_mtok'];
            $revenuePerTurn = 2 * (int) $tier['credits_per_message'] * $worstPerCredit;

            // WORST case (maxed 8k context, every turn a 2-call tool loop):
            // must never lose money, with a 10% buffer.
            $costHigh = 2 * ((8_000 / 1_000_000) * (float) $rates['in'] + (800 / 1_000_000) * (float) $rates['out']);
            $marginHigh = 1 - ($costHigh / $revenuePerTurn);
            $this->assertGreaterThan(
                0.10,
                $marginHigh,
                "Tier '{$key}' margin at WORST-case usage on the cheapest credits is ".round($marginHigh * 100).'% — repricing required.',
            );

            // TYPICAL case (2-call turn ≈ 3.2k in / 300 out per call):
            // must clear 50%.
            $costMid = 2 * ((3_200 / 1_000_000) * (float) $rates['in'] + (300 / 1_000_000) * (float) $rates['out']);
            $marginMid = 1 - ($costMid / $revenuePerTurn);
            $this->assertGreaterThan(
                0.50,
                $marginMid,
                "Tier '{$key}' margin at TYPICAL usage on the cheapest credits is ".round($marginMid * 100).'% — repricing required.',
            );
        }
    }

    public function test_free_tier_allotment_stays_capped(): void
    {
        // The Free tier is the one plan with no revenue to defend a margin
        // with, so its exposure is bounded by the allotment alone. At the
        // served tier (gpt/nano, 1 credit/message) 100 credits costs us
        // fractions of a cent per team per month; a careless bump here is the
        // only way a free signup becomes expensive. Raise deliberately.
        $this->assertSame(0, Plan::Free->priceEur(), 'The Free tier must stay free.');
        $this->assertLessThanOrEqual(
            500,
            Plan::Free->monthlyCredits(),
            'Free allotment above 500 credits — re-run the acquisition-cost maths before raising it.',
        );
        $this->assertFalse(
            Plan::Free->allowsTopUps(),
            'Free must not buy top-ups — the cap is the upgrade prompt.',
        );
    }

    public function test_the_paid_ladder_improves_per_credit_as_it_climbs(): void
    {
        // A rung that costs more per credit than the one below it is a broken
        // ladder — nobody should pay more to get a worse rate.
        $rungs = [Plan::Starter, Plan::Growth, Plan::Pro];
        $previous = null;
        foreach ($rungs as $plan) {
            $perCredit = $plan->priceEur() / $plan->monthlyCredits();
            if ($previous !== null) {
                $this->assertLessThanOrEqual(
                    $previous['rate'],
                    $perCredit,
                    "{$plan->label()} (€{$perCredit}/credit) is worse value than {$previous['label']} (€{$previous['rate']}/credit).",
                );
            }
            $previous = ['rate' => $perCredit, 'label' => $plan->label()];
        }
    }
}
