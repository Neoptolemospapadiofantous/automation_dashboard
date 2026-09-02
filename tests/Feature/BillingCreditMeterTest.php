<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit metering: CreditMeter atomically debits + audits; out-of-credits
 * teams get a 402 from the chat endpoints; renewal resets the balance
 * (no rollover). Pre-call + post-call both have guard responsibilities.
 */
class BillingCreditMeterTest extends TestCase
{
    use RefreshDatabase;

    private function fakeLlm(): void
    {
        $this->fakeCore([['text' => 'Hi!', 'in' => 5, 'out' => 5]]);
    }

    public function test_consume_decrements_balance_and_writes_audit_row(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $start = $team->credit_balance;

        (new CreditMeter)->consume($team, 3, agentId: null, meta: ['source' => 'test']);

        $this->assertSame($start - 3, $team->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => -3,
            'reason' => 'consume_message',
        ]);
    }

    public function test_consume_throws_out_of_credits_when_insufficient(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill(['credit_balance' => 2])->save();

        $this->expectException(OutOfCredits::class);
        (new CreditMeter)->consume($team, 3);
    }

    public function test_interact_endpoint_returns_402_when_team_is_dry(): void
    {
        $this->fakeLlm();
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
        ]);
        $user->currentTeam->forceFill([
            'current_agent_id' => $agent->id,
            'credit_balance' => 0,
        ])->save();

        $this->actingAs($user->fresh())
            ->postJson(route('chat.interact'), ['user_id' => 'web-x', 'message' => 'hello'])
            ->assertStatus(402)
            ->assertJsonStructure(['error', 'plan', 'plan_label', 'allows_topups']);
    }

    public function test_interact_decrements_credits_per_message(): void
    {
        $this->fakeLlm();
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();
        $start = $user->currentTeam->credit_balance;

        $this->actingAs($user->fresh())
            ->postJson(route('chat.interact'), ['user_id' => 'web-x', 'message' => 'hi'])
            ->assertOk();

        // 1 user message + 1 agent reply (the fake returns 1 text trace)
        // = 2 messages x Flowstack Core's 1 credit = 2 credits.
        $this->assertSame($start - 2, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_renewal_resets_monthly_but_topups_roll_over(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        // Burned most of the month; 800 PURCHASED credits still unspent.
        $team->forceFill(['credit_balance' => 12, 'topup_balance' => 800])->save();

        (new CreditMeter)->grantMonthlyRenewal($team);

        $fresh = $team->fresh();
        // Monthly bucket: hard reset, the 12 leftovers are gone.
        $this->assertSame(Plan::Free->monthlyCredits(), $fresh->credit_balance);
        // Paid bucket: untouched — customers keep what they bought.
        $this->assertSame(800, $fresh->topup_balance);
        $this->assertSame(Plan::Free->monthlyCredits() + 800, $fresh->totalCredits());
        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => Plan::Free->monthlyCredits(),
            'reason' => 'grant_renewal',
        ]);
    }

    public function test_consume_drains_monthly_before_topups(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill(['credit_balance' => 3, 'topup_balance' => 10])->save();

        (new CreditMeter)->consume($team, 5);

        $fresh = $team->fresh();
        $this->assertSame(0, $fresh->credit_balance);  // monthly emptied first
        $this->assertSame(8, $fresh->topup_balance);   // then 2 from the paid bucket
    }

    public function test_has_credits_counts_both_buckets(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill(['credit_balance' => 0, 'topup_balance' => 4])->save();

        $this->assertTrue($team->fresh()->hasCredits(4));
        $this->assertFalse($team->fresh()->hasCredits(5));
    }

    public function test_grant_topup_is_additive_for_paid_plans(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'credit_balance' => 100,
        ])->save();

        (new CreditMeter)->grantTopUp($team, 500, ['stripe_invoice' => 'inv_xxx']);

        $fresh = $team->fresh();
        // Purchased credits land in the rollover bucket, not the monthly one.
        $this->assertSame(100, $fresh->credit_balance);
        $this->assertSame(500, $fresh->topup_balance);
        $this->assertSame(600, $fresh->totalCredits());
    }

    public function test_grant_topup_refuses_custom_plan(): void
    {
        // Custom is project-based — credits are negotiated per engagement
        // and granted manually by ops, not via self-serve top-ups. Both
        // paid SaaS tiers (Starter, Operator) DO allow top-ups; only
        // Custom (Plan::Business) refuses.
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill(['plan' => Plan::Business->value])->save();

        $this->expectException(\RuntimeException::class);
        (new CreditMeter)->grantTopUp($team, 500);
    }
}
