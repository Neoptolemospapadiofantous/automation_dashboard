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

    private function team(int $balance = 1000, Plan $plan = Plan::Free): Team
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => $plan,
            'credit_balance' => $balance,
            'alert_thresholds_fired' => [],
        ])->save();

        return $team->fresh();
    }

    public function test_no_alert_below_first_threshold(): void
    {
        Notification::fake();

        $team = $this->team(1000);  // Free: 1000/mo grant. 100% balance => 0% used.
        (new CreditMeter)->consume($team, 400);  // 600 left = 40% used.

        Notification::assertNothingSent();
        $this->assertSame([], $team->fresh()->alert_thresholds_fired);
    }

    public function test_fires_single_alert_when_crossing_one_threshold(): void
    {
        Notification::fake();

        $team = $this->team(1000);
        (new CreditMeter)->consume($team, 600);  // 400 left = 60% used (crosses 50).

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_fires_two_alerts_when_jumping_past_two_thresholds(): void
    {
        Notification::fake();

        $team = $this->team(1000);
        (new CreditMeter)->consume($team, 850);  // 150 left = 85% used (crosses 50 and 80).

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 2);
        $this->assertSame(['50', '80'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_idempotent_does_not_refire_same_threshold(): void
    {
        Notification::fake();

        $team = $this->team(1000);
        (new CreditMeter)->consume($team, 600);  // crosses 50
        (new CreditMeter)->consume($team, 100);  // still in 50-80 zone, no new threshold

        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_topup_above_threshold_clears_state_and_allows_refire(): void
    {
        Notification::fake();

        // Free plan grant = 1000.
        $team = $this->team(1000);
        (new CreditMeter)->consume($team, 600);  // balance=400, 60% used, fires 50, fired=['50']
        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);

        // Top-up 500 → balance=900, 10% used → no thresholds crossed, fired=[]
        (new CreditMeter)->grantTopUp($team->fresh(), 500);
        $this->assertSame([], $team->fresh()->alert_thresholds_fired);

        // Drop back below 50% remaining (balance ≤ 500) → 50% threshold refires
        Notification::fake();  // reset counter for second-stage assertion
        (new CreditMeter)->consume($team->fresh(), 500);  // balance=400, 60% used
        Notification::assertSentTimes(CreditBurnAlertNotification::class, 1);
        $this->assertSame(['50'], $team->fresh()->alert_thresholds_fired);
    }

    public function test_renewal_resets_fired_thresholds(): void
    {
        Notification::fake();

        $team = $this->team(100);   // 90% used already → would fire 50 and 80 if a consume happened
        (new CreditMeter)->consume($team, 50);  // 50 left = 95% used → fires all 3
        $this->assertSame(['50', '80', '95'], $team->fresh()->alert_thresholds_fired);

        // Renewal resets balance + clears fired
        (new CreditMeter)->grantMonthlyRenewal($team->fresh());
        $fresh = $team->fresh();
        $this->assertSame(1000, $fresh->credit_balance);
        $this->assertSame([], $fresh->alert_thresholds_fired);
    }

    public function test_no_alerts_for_business_plan_with_zero_grant(): void
    {
        Notification::fake();

        // Business plan has monthlyCredits=0 (negotiated). Even at 1 credit
        // we must not emit alerts — no denominator to make percentage meaningful.
        $team = $this->team(1, Plan::Business);
        $team->forceFill(['credit_balance' => 1])->save();

        (new EvaluateCreditAlerts)->evaluate($team->fresh());
        Notification::assertNothingSent();
    }

    public function test_notification_payload_shape(): void
    {
        Notification::fake();

        $team = $this->team(1000);
        (new CreditMeter)->consume($team, 600);

        Notification::assertSentTo(
            $team->owner,
            CreditBurnAlertNotification::class,
            function (CreditBurnAlertNotification $n) use ($team): bool {
                return $n->thresholdPercent === 50
                    && $n->creditsRemaining === 400
                    && $n->monthlyGrant === 1000
                    && $n->team->is($team);
            },
        );
    }
}
