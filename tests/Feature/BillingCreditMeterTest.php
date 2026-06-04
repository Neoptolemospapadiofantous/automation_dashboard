<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Credit metering: CreditMeter atomically debits + audits; out-of-credits
 * teams get a 402 from the chat endpoints; renewal resets the balance
 * (no rollover). Pre-call + post-call both have guard responsibilities.
 */
class BillingCreditMeterTest extends TestCase
{
    use RefreshDatabase;

    private function fakeV4(): void
    {
        Http::fake([
            'general-runtime.voiceflow.com/v4/project/*/session' => Http::response(['sessionKey' => 's'], 200),
            'general-runtime.voiceflow.com/v4/interact' => Http::response(['traces' => [
                ['type' => 'text', 'payload' => ['message' => 'Hi!']],
            ]], 200),
            'general-runtime.voiceflow.com/state/user/*' => Http::response(['variables' => []], 200),
        ]);
    }

    public function test_consume_decrements_balance_and_writes_audit_row(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $start = $team->credit_balance;

        (new CreditMeter())->consume($team, 3, agentId: null, meta: ['source' => 'test']);

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
        (new CreditMeter())->consume($team, 3);
    }

    public function test_interact_endpoint_returns_402_when_team_is_dry(): void
    {
        $this->fakeV4();
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
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
        $this->fakeV4();
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'voiceflow_api_key' => 'VF.DM.k',
            'voiceflow_project_id' => 'p',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();
        $start = $user->currentTeam->credit_balance;

        $this->actingAs($user->fresh())
            ->postJson(route('chat.interact'), ['user_id' => 'web-x', 'message' => 'hi'])
            ->assertOk();

        // 1 user message + 1 agent reply (the fake returns 1 text trace) = 2 credits.
        $this->assertSame($start - 2, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_grant_monthly_renewal_resets_balance_no_rollover(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        // User burned through most of the month but didn't use all credits.
        $team->forceFill(['credit_balance' => 12])->save();

        (new CreditMeter())->grantMonthlyRenewal($team);

        // Hard reset to plan allotment — the 12 leftover credits are gone.
        $this->assertSame(Plan::Free->monthlyCredits(), $team->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => Plan::Free->monthlyCredits(),
            'reason' => 'grant_renewal',
        ]);
    }

    public function test_grant_topup_is_additive_for_paid_plans(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'credit_balance' => 100,
        ])->save();

        (new CreditMeter())->grantTopUp($team, 500, ['stripe_invoice' => 'inv_xxx']);

        $this->assertSame(600, $team->fresh()->credit_balance);
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
        (new CreditMeter())->grantTopUp($team, 500);
    }
}
