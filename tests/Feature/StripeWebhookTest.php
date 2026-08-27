<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Stripe\Event;
use Tests\TestCase;

/**
 * Stripe webhook handler. We don't actually verify the signature in
 * tests (that would require recomputing the HMAC); instead we replace
 * the StripeClient's verifyWebhook with a stub that returns a hand-
 * built Event. The rest of the handler (event dispatch + side effects)
 * is exercised normally.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_returns_400(): void
    {
        $this->postJson('/webhooks/stripe', ['type' => 'noise'])
            ->assertStatus(400);
    }

    public function test_subscription_completes_and_grants_credits(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Business->value, 'credit_balance' => 0])->save();

        $this->fakeWebhook($this->subscriptionCompletedEvent(
            teamId: (string) $team->id,
            subscriptionId: 'sub_test_123',
            planValue: Plan::Starter->value,
        ));

        $fresh = $team->fresh();
        $this->assertSame(Plan::Starter, $fresh->plan);
        $this->assertSame('sub_test_123', $fresh->stripe_subscription_id);
        $this->assertSame('active', $fresh->stripe_subscription_status);
        $this->assertSame(Plan::Starter->monthlyCredits(), $fresh->credit_balance);
    }

    public function test_topup_completes_and_grants_credits(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Starter->value, 'credit_balance' => 100])->save();

        $this->fakeWebhook($this->topupCompletedEvent(
            teamId: (string) $team->id,
            sessionId: 'cs_test_topup_1',
            credits: 5_000,
        ));

        $fresh = $team->fresh();
        // Purchased credits land in the rollover bucket (policy 2026-06-12).
        $this->assertSame(100, $fresh->credit_balance);
        $this->assertSame(5_000, $fresh->topup_balance);
        $this->assertSame(5_100, $fresh->totalCredits());
        $this->assertSame(1, CreditTransaction::query()->where('reason', 'grant_topup')->count());
    }

    public function test_topup_is_idempotent_on_duplicate_delivery(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Starter->value, 'credit_balance' => 0])->save();

        $event = $this->topupCompletedEvent(
            teamId: (string) $team->id,
            sessionId: 'cs_test_dup',
            credits: 1_000,
        );

        $this->fakeWebhook($event);
        $this->fakeWebhook($event); // Stripe retries — same session id

        $this->assertSame(1_000, $team->fresh()->topup_balance);
        $this->assertSame(1, CreditTransaction::query()->where('reason', 'grant_topup')->count());
    }

    public function test_custom_topup_grants_credits_derived_from_amount_paid(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Starter->value, 'credit_balance' => 100])->save();

        // €50 paid (5000 cents) × 500 credits/€ = 25,000 credits. No `credits`
        // in metadata — the handler must read amount_total.
        $this->fakeWebhook($this->customTopupCompletedEvent(
            teamId: (string) $team->id,
            sessionId: 'cs_test_custom_1',
            amountTotalCents: 5_000,
        ));

        $fresh = $team->fresh();
        $this->assertSame(100, $fresh->credit_balance);
        $this->assertSame(25_000, $fresh->topup_balance);
        $this->assertSame(1, CreditTransaction::query()->where('reason', 'grant_topup')->count());
    }

    public function test_invoice_payment_failed_marks_team_past_due(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Starter->value,
            'stripe_subscription_id' => 'sub_test_failing',
            'stripe_subscription_status' => 'active',
        ])->save();

        $this->fakeWebhook($this->invoicePaymentFailedEvent('sub_test_failing'));

        $this->assertSame('past_due', $team->fresh()->stripe_subscription_status);
    }

    public function test_subscription_deleted_downgrades_to_free(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'stripe_subscription_id' => 'sub_test_to_cancel',
            'stripe_subscription_status' => 'active',
        ])->save();

        $this->fakeWebhook($this->subscriptionDeletedEvent('sub_test_to_cancel'));

        $fresh = $team->fresh();
        // Cancellation drops the team to the Free rung, not to the paid
        // entry tier — it keeps a working (capped) agent, billed nothing.
        $this->assertSame(Plan::Free, $fresh->plan);
        $this->assertNull($fresh->stripe_subscription_id);
        $this->assertSame('canceled', $fresh->stripe_subscription_status);
    }

    /* ---------- helpers ---------- */

    private function fakeWebhook(Event $event): TestResponse
    {
        // Bypass signature verification by replacing StripeClient::verifyWebhook
        // with a method that returns the prebuilt event.
        $this->mock(StripeClient::class, function (MockInterface $mock) use ($event): void {
            $mock->shouldReceive('verifyWebhook')->andReturn($event);
        });

        return $this->postJson('/webhooks/stripe', [], ['Stripe-Signature' => 'irrelevant-because-mocked']);
    }

    private function subscriptionCompletedEvent(string $teamId, string $subscriptionId, string $planValue): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_'.uniqid(),
                    'mode' => 'subscription',
                    'subscription' => $subscriptionId,
                    'metadata' => [
                        'team_id' => $teamId,
                        'plan_value' => $planValue,
                    ],
                ],
            ],
        ]);
    }

    private function topupCompletedEvent(string $teamId, string $sessionId, int $credits): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'mode' => 'payment',
                    'metadata' => [
                        'team_id' => $teamId,
                        'pack' => 'medium',
                        'credits' => (string) $credits,
                    ],
                ],
            ],
        ]);
    }

    private function customTopupCompletedEvent(string $teamId, string $sessionId, int $amountTotalCents): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'mode' => 'payment',
                    'amount_total' => $amountTotalCents,
                    'currency' => 'eur',
                    'metadata' => [
                        'team_id' => $teamId,
                        'pack' => 'custom',
                    ],
                ],
            ],
        ]);
    }

    private function invoicePaymentFailedEvent(string $subscriptionId): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_'.uniqid(),
                    'subscription' => $subscriptionId,
                ],
            ],
        ]);
    }

    private function subscriptionDeletedEvent(string $subscriptionId): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'status' => 'canceled',
                ],
            ],
        ]);
    }

    public function test_a_price_switch_moves_the_entitlement_not_just_the_status(): void
    {
        // customer.subscription.updated is how a plan switch arrives. Syncing
        // only the status would leave the team paying the new price while
        // keeping the old rung's agent cap and allotment.
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $team = Team::factory()->create([
            'plan' => Plan::Starter->value,
            'stripe_subscription_id' => 'sub_switch_1',
            'stripe_subscription_status' => 'active',
        ]);

        $this->fakeWebhook($this->subscriptionUpdatedEvent('sub_switch_1', 'price_growth_m'));

        $fresh = $team->fresh();
        $this->assertSame(Plan::Growth, $fresh->plan);
        $this->assertSame(5, $fresh->planObject()->maxAgents());
        $this->assertTrue((bool) $fresh->stripe_cancel_at_period_end === false);
    }

    public function test_a_scheduled_cancellation_is_mirrored_from_stripe(): void
    {
        $team = Team::factory()->create([
            'plan' => Plan::Growth->value,
            'stripe_subscription_id' => 'sub_switch_2',
            'stripe_subscription_status' => 'active',
        ]);

        $this->fakeWebhook($this->subscriptionUpdatedEvent('sub_switch_2', null, cancelAtPeriodEnd: true));

        $this->assertTrue((bool) $team->fresh()->stripe_cancel_at_period_end);
        // Scheduled, not applied — they keep the plan until the period ends.
        $this->assertSame(Plan::Growth, $team->fresh()->plan);
    }

    public function test_an_upgrade_invoice_grants_the_new_allotment_even_if_it_arrives_first(): void
    {
        // Stripe does not order its events. If invoice.paid lands before
        // customer.subscription.updated, reading the plan column would grant
        // the OLD, smaller allotment — so the handler reads the invoice price.
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $team = Team::factory()->create([
            'plan' => Plan::Starter->value,      // not yet updated
            'stripe_subscription_id' => 'sub_switch_3',
            'stripe_subscription_status' => 'active',
            'credit_balance' => 100,
        ]);

        $this->fakeWebhook($this->invoicePaidEvent('sub_switch_3', 'price_growth_m', 'subscription_update'));

        $fresh = $team->fresh();
        $this->assertSame(Plan::Growth, $fresh->plan);
        $this->assertSame(Plan::Growth->monthlyCredits(), (int) $fresh->credit_balance);
    }

    public function test_a_downgrade_invoice_does_not_confiscate_paid_credits(): void
    {
        config(['billing.stripe_price.starter' => 'price_starter_m']);
        $team = Team::factory()->create([
            'plan' => Plan::Growth->value,
            'stripe_subscription_id' => 'sub_switch_4',
            'stripe_subscription_status' => 'active',
            'credit_balance' => 9_000,           // paid for at the higher rung
        ]);

        $this->fakeWebhook($this->invoicePaidEvent('sub_switch_4', 'price_starter_m', 'subscription_update'));

        $fresh = $team->fresh();
        $this->assertSame(Plan::Starter, $fresh->plan);          // entitlement moves
        $this->assertSame(9_000, (int) $fresh->credit_balance);  // credits do not
    }

    public function test_a_true_renewal_still_resets_the_bucket(): void
    {
        config(['billing.stripe_price.starter' => 'price_starter_m']);
        $team = Team::factory()->create([
            'plan' => Plan::Starter->value,
            'stripe_subscription_id' => 'sub_switch_5',
            'stripe_subscription_status' => 'active',
            'credit_balance' => 9_000,
        ]);

        $this->fakeWebhook($this->invoicePaidEvent('sub_switch_5', 'price_starter_m', 'subscription_cycle'));

        // A period boundary DOES reset — leftovers expire, that is the deal.
        $this->assertSame(Plan::Starter->monthlyCredits(), (int) $team->fresh()->credit_balance);
    }

    private function subscriptionUpdatedEvent(string $subscriptionId, ?string $priceId = null, bool $cancelAtPeriodEnd = false): Event
    {
        $object = [
            'id' => $subscriptionId,
            'status' => 'active',
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ];
        if ($priceId !== null) {
            $object['items'] = ['data' => [['price' => ['id' => $priceId]]]];
        }

        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'customer.subscription.updated',
            'data' => ['object' => $object],
        ]);
    }

    private function invoicePaidEvent(string $subscriptionId, string $priceId, string $billingReason): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_'.uniqid(),
                    'subscription' => $subscriptionId,
                    'billing_reason' => $billingReason,
                    'lines' => ['data' => [[
                        'price' => ['id' => $priceId],
                        'period' => ['end' => now()->addMonth()->timestamp],
                    ]]],
                ],
            ],
        ]);
    }
}
