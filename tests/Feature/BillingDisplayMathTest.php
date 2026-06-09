<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
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
        $grant = Plan::Free->monthlyCredits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => $grant,
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
        $team->forceFill(['credit_balance' => $grant + 100])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // credits_total snaps to grant + 100 top-up.
                ->where('billing.credits_total', $grant + 100)
                // credits_used stays at 0 — nothing consumed yet.
                ->where('billing.credits_used', 0)
                ->where('billing.credits_remaining', $grant + 100)
            );
    }

    public function test_credits_used_reflects_consumption_after_topup(): void
    {
        $grant = Plan::Free->monthlyCredits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            // started at grant + 100 (after top-up), consumed 850
            'credit_balance' => $grant + 100 - 850,
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
                ->where('billing.credits_total', $grant + 100)
                ->where('billing.credits_used', 850)
                ->where('billing.credits_remaining', $grant + 100 - 850)
            );
    }

    public function test_topups_from_previous_periods_are_not_counted(): void
    {
        $grant = Plan::Free->monthlyCredits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => $grant,
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
                ->where('billing.credits_total', $grant)
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
        $grant = Plan::Pro->monthlyCredits();
        $medium = TopUpPack::Medium->credits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'credit_balance' => $grant,
            'credits_renewed_at' => now()->subDays(1),
        ])->save();

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Medium->value])
            ->assertRedirect();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', $grant + $medium)
                ->where('billing.credits_remaining', $grant + $medium)
                ->where('billing.credits_used', 0)
            );
    }

    // -------------------------------------------------------------------
    // Zero-balance scenarios: when the user has burned every credit, the
    // bar should pin at 100% used / 0 remaining without the denominator
    // mysteriously shrinking. Per industry-standard (Pattern A), the max
    // stays at "everything granted this period."
    // -------------------------------------------------------------------

    public function test_zero_balance_no_topups_pins_bar_at_100_percent(): void
    {
        // Pure monthly allotment, fully consumed. credits_used equals
        // credits_total so the front-end's `usedPercent` lands at 100%
        // (rose-500 in the CreditMeter tone map).
        $grant = Plan::Free->monthlyCredits();
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 0,
            'credits_renewed_at' => now()->subDays(5),
        ])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', $grant)
                ->where('billing.credits_used', $grant)
                ->where('billing.credits_remaining', 0)
            );
    }

    public function test_zero_balance_with_topups_keeps_full_period_denominator(): void
    {
        // Monthly + one Small top-up earlier in the period → all consumed.
        // Critical assertion: credits_total stays at grant + Small so the
        // user can see they burned through both. Pattern A: the
        // denominator never shrinks within a period.
        $grant = Plan::Free->monthlyCredits();
        $small = TopUpPack::Small->credits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 0,
            'credits_renewed_at' => now()->subDays(5),
        ])->save();

        \DB::table('credit_transactions')->insert([
            'team_id' => $team->id,
            'amount' => $small,
            'reason' => CreditTransaction::REASON_GRANT_TOPUP,
            'meta' => json_encode(['pack' => 'small']),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', $grant + $small)
                ->where('billing.credits_used', $grant + $small)
                ->where('billing.credits_remaining', 0)
            );
    }

    public function test_topup_at_zero_drops_bar_proportionally(): void
    {
        // User hits zero after consuming (monthly + first topup). They
        // buy a SECOND top-up to keep going. The denominator should now
        // include BOTH top-ups — they regain headroom without losing the
        // audit of what they already spent.
        $grant = Plan::Free->monthlyCredits();
        $small = TopUpPack::Small->credits();
        $consumed = $grant + $small; // burned through monthly + first topup
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 0,
            'credits_renewed_at' => now()->subDays(5),
        ])->save();

        // First top-up — already consumed.
        \DB::table('credit_transactions')->insert([
            'team_id' => $team->id,
            'amount' => $small,
            'reason' => CreditTransaction::REASON_GRANT_TOPUP,
            'meta' => json_encode(['pack' => 'small']),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        // Buy a second top-up via the real endpoint.
        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Small->value])
            ->assertRedirect();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', $grant + $small + $small) // monthly + both topups
                ->where('billing.credits_used', $consumed) // first burn-through stays counted
                ->where('billing.credits_remaining', $small) // new topup is the available balance
            );
    }

    public function test_monthly_renewal_resets_denominator_and_drops_prior_topups(): void
    {
        // The calendar period rolled over. grantMonthlyRenewal bumps
        // credits_renewed_at and hard-resets balance to the plan's
        // monthly allotment. Past-period top-ups (now BEFORE
        // credits_renewed_at) should drop out of the sum entirely —
        // fresh "0 / grant" display.
        $grant = Plan::Free->monthlyCredits();
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => $grant,
            // Renewal JUST happened (boundary is now).
            'credits_renewed_at' => now(),
        ])->save();

        // Stale top-up from the prior period. Must NOT contribute.
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
                // Just the plan monthly — prior-period top-ups excluded.
                ->where('billing.credits_total', $grant)
                ->where('billing.credits_used', 0)
                ->where('billing.credits_remaining', $grant)
            );
    }

    public function test_out_of_credits_blocks_consume_at_zero(): void
    {
        // Backend invariant: at zero balance the meter must REFUSE further
        // consumption. The 402-mapping exception renderer + the UI
        // upgrade prompt are downstream of this; if consume() ever lets a
        // zero-balance message through, every fix above is academic.
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => 0,
            'credits_renewed_at' => now()->subDays(1),
        ])->save();

        $this->expectException(OutOfCredits::class);
        (new CreditMeter)->consume($team, 1);
    }
}
