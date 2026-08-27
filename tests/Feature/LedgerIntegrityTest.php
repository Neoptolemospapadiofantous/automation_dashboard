<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\CreditTransaction;
use App\Models\RuntimeUsage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Financial integrity additions from the audit-system gap review
 * (2026-06-12): the ledger must sum to the live balances through every
 * lifecycle, and the daily spend tripwire must trip.
 */
class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewal_records_expired_leftovers_so_the_ledger_balances(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        // Simulate a clean ledger start: initial grant row matching balance.
        $team->forceFill(['plan' => Plan::Starter->value, 'credit_balance' => 0, 'topup_balance' => 0])->save();
        (new CreditMeter)->grantMonthlyRenewal($team);   // ledger +2500, balance 2500

        (new CreditMeter)->consume($team->fresh(), 2_000);       // ledger -2000, balance 500
        (new CreditMeter)->grantTopUp($team->fresh(), 1_000);    // ledger +1000, topup 1000
        (new CreditMeter)->grantMonthlyRenewal($team->fresh());  // wipes 500 → MUST record -500

        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => -500,
            'reason' => CreditTransaction::REASON_EXPIRE_MONTHLY,
        ]);

        // The invariant itself: SUM(ledger) == monthly + topup.
        $ledger = (int) CreditTransaction::where('team_id', $team->id)->sum('amount');
        $fresh = $team->fresh();
        $this->assertSame($fresh->credit_balance + $fresh->topup_balance, $ledger);
        $this->assertSame(Plan::Starter->monthlyCredits() + 1_000, $ledger);
    }

    public function test_reconcile_command_passes_on_clean_books_and_fails_on_drift(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $team->forceFill(['credit_balance' => 0, 'topup_balance' => 0])->save();
        (new CreditMeter)->grantMonthlyRenewal($team);

        $this->artisan('credits:reconcile')
            ->expectsOutputToContain('Ledger reconciles')
            ->assertExitCode(0);

        // Introduce drift the way a buggy code path would: balance moved
        // without an audit row.
        Team::where('id', $team->id)->update(['credit_balance' => 9_999]);

        $this->artisan('credits:reconcile')->assertExitCode(1);
    }

    public function test_spend_check_trips_when_yesterday_crossed_the_ceiling(): void
    {
        config(['sla.spend.daily_ceiling_usd' => 1.0]);

        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        // 500M output tokens on haiku ≈ $2,500 — comfortably over $1.
        RuntimeUsage::create([
            'team_id' => $team->id, 'agent_id' => $agent->id,
            'date' => now()->subDay()->startOfDay(), 'tier' => 'haiku',
            'turns' => 10, 'tokens_in' => 0, 'tokens_out' => 500_000_000,
        ]);

        $this->artisan('runtime:spend-check')->assertExitCode(1);
    }

    public function test_spend_check_passes_a_quiet_day(): void
    {
        $this->artisan('runtime:spend-check')->assertExitCode(0);
    }
}
