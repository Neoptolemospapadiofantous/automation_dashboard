<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Top-up purchase HTTP flow. Stripe Checkout-backed: POST /billing/topup
 * redirects the browser to Stripe; the actual credit grant happens in the
 * webhook handler (see StripeWebhookHandlerTest). These tests cover only
 * the synchronous controller contract.
 */
class BillingTopUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_redirects_to_stripe_checkout(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Free->value, 'credit_balance' => 500])->save();

        // Mock StripeClient::createOneOffCheckout so no Stripe API call fires.
        $sessionStub = Session::constructFrom(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123']);
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock) use ($sessionStub): void {
            $mock->shouldReceive('createOneOffCheckout')->once()->andReturn($sessionStub);
        });
        // The price_id must be configured for the controller to proceed.
        config(['billing.stripe_price.topup_medium' => 'price_test_medium']);

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Medium->value])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        // Balance is NOT touched synchronously — the webhook grants on payment.
        $this->assertSame(500, $team->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_operator_redirects_to_stripe_checkout(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Pro->value, 'credit_balance' => 1_000])->save();

        $sessionStub = Session::constructFrom(['id' => 'cs_test_456', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_456']);
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock) use ($sessionStub): void {
            $mock->shouldReceive('createOneOffCheckout')->once()->andReturn($sessionStub);
        });
        config(['billing.stripe_price.topup_large' => 'price_test_large']);

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Large->value])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_456');

        $this->assertSame(1_000, $team->fresh()->credit_balance);
    }

    public function test_custom_plan_rejects_topup(): void
    {
        // Custom is project-based — credits are negotiated, not self-served.
        // The endpoint 403s and never reaches Stripe.
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Business->value, 'credit_balance' => 100])->save();

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Small->value])
            ->assertStatus(403);

        $this->assertSame(100, $team->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_unknown_pack_id_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Free->value])->save();

        $this->actingAs($user->fresh())
            ->postJson(route('billing.topup'), ['pack' => 'mega-jumbo'])
            ->assertStatus(422);
    }

    public function test_pack_without_stripe_price_id_returns_friendly_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Free->value])->save();

        // Force the pack's stripe price id to null.
        config(['billing.stripe_price.topup_small' => null]);

        $this->actingAs($user->fresh())
            ->from(route('billing.index'))
            ->post(route('billing.topup'), ['pack' => TopUpPack::Small->value])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors(['pack']);
    }
}
