<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Models\Agent;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DB/HTTP-bound billing invariants: credit debits, balance gating, and the
 * renewal safety-net command. The pure pricing/margin math (Plan/TopUpPack
 * enums + pricing config, no DB) lives in
 * tests/Unit/Billing/PricingInvariantsTest.php.
 */
class BillingInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_kb_query_debits_the_tier_multiplier(): void
    {
        config([
            'runtime.llm.anthropic.api_key' => 'sk-test',
            'runtime.embeddings.openai_api_key' => 'sk-test',
            'runtime.embeddings.dimensions' => 4,
        ]);
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [0.5, 0.5, 0.5, 0.5]]]]),
            // KB synthesis runs on Flowstack Core, like the agent itself.
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'It costs $99.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $user = $this->owner();
        app(KnowledgeStore::class)
            ->ingestDocument($user->currentTeam->currentAgent->id, 'Pricing', 'Starter costs $99.', ['source' => 'text']);

        $this->actingAs($user)->postJson(route('knowledge.query'), ['question' => 'price?'])->assertOk();

        $this->assertSame(99, $user->currentTeam->fresh()->credit_balance); // 1 message x Core's 1 credit
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

    public function test_a_new_team_can_use_the_product_immediately_on_the_free_tier(): void
    {
        // The free tier is an entitlement, not an unpaid invoice. Before the
        // 2026-08-27 fix a fresh signup sat at 0 credits until the nightly
        // credits:grant-renewals ran — up to 24 hours during which its agent
        // could not answer a single message, silently defeating the free tier.
        config(['billing.grant_on_signup' => false]);   // prod's setting

        $team = Team::factory()->create();

        $this->assertSame(Plan::Free, $team->planObject());
        $this->assertSame(
            Plan::Free->monthlyCredits(),
            (int) $team->fresh()->credit_balance,
            'A new free team must be able to answer immediately, not after the nightly job.',
        );
        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => Plan::Free->monthlyCredits(),
            'reason' => CreditTransaction::REASON_GRANT_RENEWAL,
        ]);
    }

    public function test_a_paid_plan_is_not_granted_credits_without_stripe(): void
    {
        // The other half of the rule: a paid rung must never self-grant on
        // creation, or someone could subscribe, never pay, and keep the
        // allotment. Stripe's invoice.paid is the only trigger.
        config(['billing.grant_on_signup' => false]);

        $team = Team::factory()->create(['plan' => Plan::Pro->value, 'credit_balance' => 0]);

        $this->assertSame(0, (int) $team->fresh()->credit_balance);
        $this->assertDatabaseMissing('credit_transactions', [
            'team_id' => $team->id,
            'reason' => CreditTransaction::REASON_GRANT_RENEWAL,
        ]);
    }
}
