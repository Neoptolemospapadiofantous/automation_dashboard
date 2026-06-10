<?php

namespace Tests\Feature;

use App\Billing\BillingCycle;
use App\Billing\Plan;
use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Checkout\Session;
use Tests\TestCase;

class AnnualBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_returns_annual_price_id_when_configured(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => 'price_starter_annual',
        ]);

        $this->assertSame('price_starter_monthly', Plan::Free->stripePriceId(BillingCycle::Monthly));
        $this->assertSame('price_starter_annual', Plan::Free->stripePriceId(BillingCycle::Annual));
    }

    public function test_plan_returns_null_annual_when_unconfigured(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => null,
        ]);

        $this->assertSame('price_starter_monthly', Plan::Free->stripePriceId(BillingCycle::Monthly));
        $this->assertNull(Plan::Free->stripePriceId(BillingCycle::Annual));
    }

    public function test_from_stripe_price_id_recognizes_annual(): void
    {
        config([
            'billing.stripe_price.operator' => 'price_operator_monthly',
            'billing.stripe_price.operator_annual' => 'price_operator_annual',
        ]);

        $this->assertSame(Plan::Pro, Plan::fromStripePriceId('price_operator_monthly'));
        $this->assertSame(Plan::Pro, Plan::fromStripePriceId('price_operator_annual'));
    }

    public function test_annual_equivalent_monthly_applies_savings(): void
    {
        // Starter is $99/mo; 17% off → ~$82/mo equivalent.
        $this->assertSame(82, Plan::Free->annualEquivalentMonthlyUsd());
        // Operator is $399/mo; 17% off → ~$331/mo equivalent.
        $this->assertSame(331, Plan::Pro->annualEquivalentMonthlyUsd());
        // Business has no monthly price → null.
        $this->assertNull(Plan::Business->annualEquivalentMonthlyUsd());
    }

    public function test_subscribe_with_annual_cycle_uses_annual_price(): void
    {
        config([
            'billing.stripe_price.operator' => 'price_op_monthly',
            'billing.stripe_price.operator_annual' => 'price_op_annual',
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        $this->mock(StripeClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSubscriptionCheckout')
                ->once()
                ->withArgs(function ($team, $priceId, $successUrl, $cancelUrl, $metadata) {
                    return $priceId === 'price_op_annual'
                        && ($metadata['cycle'] ?? null) === 'annual';
                })
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_test',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test',
                ]));
        });

        $this->actingAs($user)
            ->post(route('subscribe.start', 'operator').'?cycle=annual')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test');
    }

    public function test_subscribe_defaults_to_monthly_when_no_cycle(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => 'price_starter_annual',
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        $this->mock(StripeClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSubscriptionCheckout')
                ->once()
                ->withArgs(fn ($team, $priceId) => $priceId === 'price_starter_monthly')
                ->andReturn(Session::constructFrom([
                    'id' => 'cs_test',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test',
                ]));
        });

        $this->actingAs($user)
            ->post(route('subscribe.start', 'starter'))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test');
    }

    public function test_subscribe_annual_without_price_configured_shows_friendly_error(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => null,
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.start', 'starter').'?cycle=annual')
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors(['plan']);
    }

    public function test_billing_page_exposes_plan_catalog_with_annual_availability(): void
    {
        config([
            'billing.stripe_price.starter' => 'price_starter_monthly',
            'billing.stripe_price.starter_annual' => 'price_starter_annual',
            'billing.stripe_price.operator' => 'price_operator_monthly',
            'billing.stripe_price.operator_annual' => null,
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('plan_catalog.starter', fn ($p) => $p
                    ->where('annual_available', true)
                    ->where('monthly_usd', 99)
                    ->where('annual_equivalent_monthly_usd', 82)
                    ->etc()
                )
                ->has('plan_catalog.operator', fn ($p) => $p
                    ->where('annual_available', false)
                    ->where('monthly_usd', 399)
                    ->etc()
                )
            );
    }
}
