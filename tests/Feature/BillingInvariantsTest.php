<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Models\Agent;
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
