<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Stripe checkout flows for monthly/annual subscribe + the billing catalog
 * page. The pure Plan price-id / annual-math resolution lives in
 * tests/Unit/Billing/PlanPricingTest.php.
 */
class AnnualBillingTest extends TestCase
{
    use RefreshDatabase;

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
                    ->where('monthly_eur', 9)
                    ->where('annual_eur', 90)
                    ->where('annual_equivalent_monthly_eur', 8)
                    ->etc()
                )
                ->has('plan_catalog.growth', fn ($p) => $p
                    ->where('monthly_eur', 19)
                    ->etc()
                )
                ->has('plan_catalog.operator', fn ($p) => $p
                    ->where('annual_available', false)
                    ->where('monthly_eur', 39)
                    ->etc()
                )
            );
    }
}
