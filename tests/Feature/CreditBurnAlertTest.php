<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\EvaluateCreditAlerts;
use App\Billing\Plan;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CreditBurnAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreditBurnAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a team at full grant for the given plan. Balance scales with
     * Plan->monthlyCredits() so future repricing doesn't break these tests.
     */
    private function team(Plan $plan = Plan::Free): Team
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => $plan,
            'credit_balance' => $plan->monthlyCredits(),
            'alert_thresholds_fired' => [],
        ])->save();

        return $team->fresh();
    }

    /** Convert a percent-of-grant to a concrete consume amount. */
    private function consumeOf(int $percent, Plan $plan = Plan::Free): int
    {
        return (int) floor(($plan->monthlyCredits() * $percent) / 100);
    }

    public function test_no_alert_below_first_threshold(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(40)); // 40% used, below 50%

        Notification::assertNothingSent();
        $this->assertSame([], $team->fresh()->alert_thresholds_fired);
    }

    public function test_fires_single_alert_when_crossing_one_threshold(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(60)); // 60% used, crosses 50

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_fires_two_alerts_when_jumping_past_two_thresholds(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(85)); // 85% used, crosses 50 + 80

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 2);
        $this->assertSame(['50', '80'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_idempotent_does_not_refire_same_threshold(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(60)); // crosses 50
        (new CreditMeter)->consume($team, $this->consumeOf(10)); // still in 50–80 zone

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_topup_above_threshold_clears_state_and_allows_refire(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(60)); // crosses 50, fired=['50']
        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);

        // Top-up enough to lift balance to ~90% remaining → no thresholds crossed
        (new CreditMeter)->grantTopUp($team->fresh(), $this->consumeOf(50));
        $this->assertSame([], $team->fresh()->alert_thresholds_fired);

        // Drop back below 50% remaining → 50% threshold refires
        Notification::fake();  // reset counter for second-stage assertion
        (new CreditMeter)->consume($team->fresh(), $this->consumeOf(50));
        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_renewal_resets_fired_thresholds(): void
    {
        Notification::fake();

        $team = $this->team();
        (new CreditMeter)->consume($team, $this->consumeOf(96)); // 96% used → all 3 fire
        $this->assertSame(['50', '80', '95'], $team->fresh()->alert_thresholds_fired);

        // Renewal resets balance + clears fired
        (new CreditMeter)->grantMonthlyRenewal($team->fresh());
        $fresh = $team->fresh();
        $this->assertSame(Plan::Free->monthlyCredits(), $fresh->credit_balance);
        $this->assertSame([], $fresh->alert_thresholds_fired);
    }

    public function test_no_alerts_for_business_plan_with_zero_grant(): void
    {
        Notification::fake();

        // Business plan has monthlyCredits=0 (negotiated). Even at 1 credit
        // we must not emit alerts — no denominator to make percentage meaningful.
        $team = $this->team(Plan::Business);
        $team->forceFill(['credit_balance' => 1])->save();

        (new EvaluateCreditAlerts)->evaluate($team->fresh());
        Notification::assertNothingSent();
    }

    public function test_notification_payload_shape(): void
    {
        Notification::fake();

        $grant = Plan::Free->monthlyCredits();
        $consumed = $this->consumeOf(60);
        $remaining = $grant - $consumed;

        $team = $this->team();
        (new CreditMeter)->consume($team, $consumed);

        Notification::assertSentTo(
            $team->owner,
            CreditBurnAlertNotification::class,
            function (CreditBurnAlertNotification $n) use ($team, $grant, $remaining): bool {
                return $n->thresholdPercent === 50
                    && $n->creditsRemaining === $remaining
                    && $n->monthlyGrant === $grant
                    && $n->team->is($team);
            },
        );
    }
}
