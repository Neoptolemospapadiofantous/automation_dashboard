<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Standing pricing invariants from the 2026-06-11 pricing audit
 * (docs/operations/pricing-audit.md). If any of these fail, someone
 * repriced plans/packs/tiers without re-running the margin math.
 */
class BillingInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_topup_pack_is_pricier_per_credit_than_operator(): void
    {
        // The upgrade-pressure mechanism AND the margin floor: tier credit
        // prices are calibrated against Operator's $/credit; any pack below
        // it can turn tiers margin-negative.
        $operatorPerCredit = Plan::Pro->priceUsd() / Plan::Pro->monthlyCredits();

        foreach (TopUpPack::cases() as $pack) {
            $packPerCredit = $pack->priceUsd() / $pack->credits();

            $this->assertGreaterThan(
                $operatorPerCredit,
                $packPerCredit,
                "{$pack->label()} (\${$packPerCredit}/credit) undercuts Operator (\${$operatorPerCredit}/credit) — margin-negative risk.",
            );
        }
    }

    public function test_every_tier_is_margin_positive_at_high_usage_on_the_cheapest_credits(): void
    {
        // HIGH scenario: 2 LLM calls × (8k in / 800 out) per visitor turn.
        // Worst revenue source = cheapest $/credit across plans and packs.
        // Embed bills (1 + replies) ≈ 2 × multiplier per turn.
        $sources = [Plan::Free->priceUsd() / Plan::Free->monthlyCredits(), Plan::Pro->priceUsd() / Plan::Pro->monthlyCredits()];
        foreach (TopUpPack::cases() as $pack) {
            $sources[] = $pack->priceUsd() / $pack->credits();
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

    public function test_kb_query_debits_the_tier_multiplier(): void
    {
        config([
            'runtime.llm.anthropic.api_key' => 'sk-test',
            'runtime.embeddings.openai_api_key' => 'sk-test',
            'runtime.embeddings.dimensions' => 4,
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.5, 0.5, 0.5, 0.5]]]]),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'It costs $99.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = $this->owner();
        app(KnowledgeStore::class)
            ->ingestDocument($user->currentTeam->currentAgent->id, 'Pricing', 'Starter costs $99.', ['source' => 'text']);

        $this->actingAs($user)->postJson(route('knowledge.query'), ['question' => 'price?'])->assertOk();

        $this->assertSame(99, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_kb_query_rejects_at_zero_balance(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk-test', 'runtime.embeddings.openai_api_key' => 'sk-test']);

        $user = $this->owner();
        $user->currentTeam->forceFill(['credit_balance' => 0])->save();

        $this->actingAs($user)->postJson(route('knowledge.query'), ['question' => 'price?'])->assertStatus(402);
    }

    public function test_renewal_safety_net_grants_overdue_active_teams_only(): void
    {
        // Annual subscriber: active, last granted 60 days ago → granted.
        $due = Team::factory()->create([
            'plan' => Plan::Pro->value,
            'stripe_subscription_status' => 'active',
            'credits_renewed_at' => now()->subDays(60),
            'credit_balance' => 7,
        ]);
        // Monthly subscriber renewed by webhook 10 days ago → untouched.
        $fresh = Team::factory()->create([
            'plan' => Plan::Pro->value,
            'stripe_subscription_status' => 'active',
            'credits_renewed_at' => now()->subDays(10),
            'credit_balance' => 7,
        ]);
        // Churned team → untouched regardless of staleness.
        $churned = Team::factory()->create([
            'plan' => Plan::Pro->value,
            'stripe_subscription_status' => 'canceled',
            'credits_renewed_at' => now()->subDays(60),
            'credit_balance' => 7,
        ]);

        $this->artisan('credits:grant-renewals')->assertExitCode(0);

        $this->assertSame(Plan::Pro->monthlyCredits(), $due->fresh()->credit_balance);
        $this->assertSame(7, $fresh->fresh()->credit_balance);
        $this->assertSame(7, $churned->fresh()->credit_balance);
    }

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }
}
