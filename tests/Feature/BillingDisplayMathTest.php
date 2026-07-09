<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the TWO-BUCKET display contract (policy 2026-06-12):
 *
 *   credits_total     = the monthly grant, ALWAYS — an immutable
 *                       denominator that top-ups can never move
 *   credits_used      = monthly grant − monthly bucket
 *   credits_remaining = monthly bucket + rollover top-up bucket
 *   topup_balance     = the rollover bucket, surfaced separately
 *
 * The bug class the old single-bucket contract suffered ("0 / 1,000 used
 * · 1,100 remaining" after a top-up — bar contradicting the remaining
 * line) is structurally impossible now: purchases never touch the bar.
 */
class BillingDisplayMathTest extends TestCase
{
    use RefreshDatabase;

    public function test_topups_never_move_the_denominator(): void
    {
        $grant = Plan::Free->monthlyCredits();
        [$user, $team] = $this->teamWith(monthly: $grant);

        (new CreditMeter)->grantTopUp($team, 1_000);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_total', $grant)            // unchanged
                ->where('billing.credits_used', 0)
                ->where('billing.topup_balance', 1_000)
                ->where('billing.credits_remaining', $grant + 1_000)
            );
    }

    public function test_used_tracks_the_monthly_bucket_only(): void
    {
        $grant = Plan::Free->monthlyCredits();
        [$user, $team] = $this->teamWith(monthly: $grant, topup: 500);

        (new CreditMeter)->consume($team, 700);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_used', 700)                // monthly drained first
                ->where('billing.topup_balance', 500)               // paid bucket untouched
                ->where('billing.credits_remaining', $grant - 700 + 500)
            );
    }

    public function test_exhausted_monthly_with_topups_shows_full_bar_but_positive_remaining(): void
    {
        [$user, $team] = $this->teamWith(monthly: 0, topup: 800);
        $grant = Plan::Free->monthlyCredits();

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_used', $grant)             // bar pegged at 100%
                ->where('billing.credits_remaining', 800)           // but they can still chat
                ->where('billing.topup_balance', 800)
            );
    }

    public function test_consume_dips_into_topups_after_monthly_runs_dry(): void
    {
        [$user, $team] = $this->teamWith(monthly: 3, topup: 10);

        (new CreditMeter)->consume($team, 5);

        $fresh = $team->fresh();
        $this->assertSame(0, $fresh->credit_balance);
        $this->assertSame(8, $fresh->topup_balance);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('billing.credits_remaining', 8));
    }

    public function test_renewal_resets_the_bar_but_keeps_purchased_credits_in_remaining(): void
    {
        $grant = Plan::Free->monthlyCredits();
        [$user, $team] = $this->teamWith(monthly: 40, topup: 900);

        (new CreditMeter)->grantMonthlyRenewal($team);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('billing.credits_used', 0)
                ->where('billing.credits_total', $grant)
                ->where('billing.credits_remaining', $grant + 900)
                ->where('billing.topup_balance', 900)
            );
    }

    /**
     * @return array{0: User, 1: Team}
     */
    /**
     * Plan::Free shares the "Starter" label with the paid entry tier, so
     * the Billing UI must key "Current plan" off a real Stripe
     * subscription, never the label — otherwise an unpaid team sees
     * "Current plan" on the €99 card and cannot buy Starter at all
     * (the bug found in the 2026-07-09 buying-journey verification).
     */
    public function test_subscribed_flag_reflects_stripe_status_not_plan_label(): void
    {
        [$user, $team] = $this->teamWith(monthly: 0);

        // Fresh unpaid team: label says Starter, but NOT subscribed.
        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page
                ->where('billing.plan_label', 'Starter')
                ->where('billing.subscribed', false)
            );

        // Webhook lands → status active → same label, now subscribed.
        $team->forceFill(['stripe_subscription_status' => 'active'])->save();
        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page->where('billing.subscribed', true));

        // Cancellation downgrades → subscribable again.
        $team->forceFill(['stripe_subscription_status' => 'canceled'])->save();
        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page->where('billing.subscribed', false));
    }

    private function teamWith(int $monthly, int $topup = 0): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Free->value,
            'credit_balance' => $monthly,
            'topup_balance' => $topup,
            'credits_renewed_at' => now()->subDays(3),
        ])->save();

        return [$user, $team];
    }
}
