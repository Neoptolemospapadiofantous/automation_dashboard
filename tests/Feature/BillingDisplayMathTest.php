<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the credits_total = monthly + top-ups-this-period invariant so
 * the bar never reads "0 / 1,000 used · 1,100 remaining" again. The
 * symptom: top up by N → balance jumps by N → naive (monthly - balance)
 * goes negative → max(0, ...) clamps used to 0 → denominator stays at
 * 1,000 while remaining is 1,100. Display contradicts itself.
 */
class BillingDisplayMathTest extends TestCase
{
    use RefreshDatabase;

    public function test_credits_total_includes_topups_granted_this_period(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 1_000,
            'credits_renewed_at' => now()->subDays(3),
        ])->save();

        // 100-credit top-up since the last renewal.
        CreditTransaction::create([
            'team_id' => $team->id,
            'amount' => 100,
            'reason' => CreditTransaction::REASON_GRANT_TOPUP,
            'meta' => ['pack' => 'small'],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $team->forceFill(['credit_balance' => 1_100])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // credits_total snaps to 1,100 (monthly 1k + 100 top-up).
                ->where('billing.credits_total', 1_100)
                // credits_used stays at 0 — nothing consumed yet.
                ->where('billing.credits_used', 0)
                ->where('billing.credits_remaining', 1_100)
            );
    }

    public function test_credits_used_reflects_consumption_after_topup(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 250, // started at 1,100 (after top-up), consumed 850
            'credits_renewed_at' => now()->subDays(3),
        ])->save();

        CreditTransaction::create([
            'team_id' => $team->id,
            'amount' => 100,
            'reason' => CreditTransaction::REASON_GRANT_TOPUP,
            'meta' => [],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', 1_100)
                ->where('billing.credits_used', 850)
                ->where('billing.credits_remaining', 250)
            );
    }

    public function test_topups_from_previous_periods_are_not_counted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 1_000,
            'credits_renewed_at' => now()->subDays(2), // renewal was 2 days ago
        ])->save();

        // Old top-up — BEFORE the most recent renewal. Should NOT be counted
        // (it was wiped by the renewal's hard reset). Insert via DB raw so
        // Eloquent's timestamp auto-fill doesn't clobber our backdated row.
        \DB::table('credit_transactions')->insert([
            'team_id' => $team->id,
            'amount' => 5_000,
            'reason' => CreditTransaction::REASON_GRANT_TOPUP,
            'meta' => json_encode([]),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Just the plan's monthly — old top-up correctly excluded.
                ->where('billing.credits_total', 1_000)
                ->where('billing.credits_used', 0)
            );
    }

    public function test_custom_plan_still_reports_null_totals(): void
    {
        // Custom is project-based: no fixed monthly cap, top-ups don't apply.
        // is_custom: true tells the UI to render "Custom — X credits remaining"
        // instead of the X/Y bar.
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill([
            'plan' => Plan::Business->value,
            'credit_balance' => 25_000,
        ])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.is_custom', true)
                ->where('billing.credits_total', null)
                ->where('billing.credits_used', null)
                ->where('billing.credits_remaining', 25_000)
            );
    }

    public function test_real_topup_purchase_keeps_math_consistent(): void
    {
        // End-to-end: hit the topup endpoint, then check the share. The
        // grant lands in credit_transactions and credits_total reflects it
        // without any further glue.
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'credit_balance' => 10_000,
            'credits_renewed_at' => now()->subDays(1),
        ])->save();

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Medium->value])
            ->assertRedirect();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', 15_000) // 10k monthly + 5k topup
                ->where('billing.credits_remaining', 15_000)
                ->where('billing.credits_used', 0)
            );
    }
}
