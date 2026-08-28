<?php

namespace Tests\Feature;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Subscription;
use Tests\TestCase;

/**
 * Self-serve plan switching: moving a LIVE subscription up or down the ladder,
 * and scheduling / undoing cancellation.
 *
 * Stripe is mocked at the StripeClient boundary — these assert OUR rules
 * (which endpoint is allowed, which proration we ask for, what happens to
 * credits), not Stripe's behaviour.
 */
class SubscriptionChangeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Plan $plan = Plan::Starter, string $status = 'active'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill([
            'plan' => $plan->value,
            'stripe_customer_id' => 'cus_test_1',
            'stripe_subscription_id' => $status === 'none' ? null : 'sub_test_1',
            'stripe_subscription_status' => $status === 'none' ? null : $status,
            'credit_balance' => 100,
        ])->save();

        return $user->fresh();
    }

    private function stripeSpy(): Mockery\MockInterface
    {
        $mock = Mockery::mock(StripeClient::class);
        $mock->shouldReceive('subscriptionPriceId')->andReturn('price_something_else')->byDefault();
        $this->app->instance(StripeClient::class, $mock);

        return $mock;
    }

    public function test_upgrade_invoices_the_difference_immediately(): void
    {
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $user = $this->owner(Plan::Starter);

        $this->stripeSpy()
            ->shouldReceive('changeSubscriptionPrice')
            ->once()
            ->withArgs(fn ($team, $priceId, $invoiceImmediately) => $priceId === 'price_growth_m' && $invoiceImmediately === true)
            ->andReturn(new Subscription('sub_test_1'));

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.change', 'growth'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_downgrade_takes_proration_as_credit_not_an_immediate_invoice(): void
    {
        config(['billing.stripe_price.starter' => 'price_starter_m']);
        $user = $this->owner(Plan::Pro);

        $this->stripeSpy()
            ->shouldReceive('changeSubscriptionPrice')
            ->once()
            ->withArgs(fn ($team, $priceId, $invoiceImmediately) => $priceId === 'price_starter_m' && $invoiceImmediately === false)
            ->andReturn(new Subscription('sub_test_1'));

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.change', 'starter'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_checkout_is_refused_when_a_subscription_is_already_live(): void
    {
        // The expensive mistake this guards: a second Checkout creates a
        // SECOND Stripe subscription and the customer is billed twice.
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $user = $this->owner(Plan::Starter);

        $mock = $this->stripeSpy();
        $mock->shouldNotReceive('createSubscriptionCheckout');

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.start', 'growth'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('plan');
    }

    public function test_change_is_refused_without_a_live_subscription(): void
    {
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $user = $this->owner(Plan::Free, 'none');

        $mock = $this->stripeSpy();
        $mock->shouldNotReceive('changeSubscriptionPrice');

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.change', 'growth'))
            ->assertSessionHasErrors('plan');
    }

    public function test_switching_to_the_price_you_are_already_on_is_rejected(): void
    {
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $user = $this->owner(Plan::Growth);

        $mock = Mockery::mock(StripeClient::class);
        $mock->shouldReceive('subscriptionPriceId')->andReturn('price_growth_m');
        $mock->shouldNotReceive('changeSubscriptionPrice');
        $this->app->instance(StripeClient::class, $mock);

        $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.change', 'growth'))
            ->assertSessionHasErrors('plan');
    }

    public function test_non_owner_cannot_change_the_plan(): void
    {
        config(['billing.stripe_price.growth' => 'price_growth_m']);
        $owner = $this->owner(Plan::Starter);
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $mock = $this->stripeSpy();
        $mock->shouldNotReceive('changeSubscriptionPrice');

        $this->actingAs($member->fresh())
            ->post(route('subscribe.change', 'growth'))
            ->assertForbidden();
    }

    public function test_cancel_is_scheduled_not_immediate_and_can_be_resumed(): void
    {
        $user = $this->owner(Plan::Growth);
        $team = $user->currentTeam;

        $mock = $this->stripeSpy();
        $mock->shouldReceive('setCancelAtPeriodEnd')->once()->withArgs(fn ($t, $c) => $c === true)->andReturn(new Subscription('sub_test_1'));

        $this->actingAs($user)->post(route('subscribe.schedule-cancel'))->assertSessionHasNoErrors();

        $team->refresh();
        $this->assertTrue((bool) $team->stripe_cancel_at_period_end);
        // Still on the plan — cancellation is scheduled, nothing is taken away.
        $this->assertSame(Plan::Growth, $team->planObject());

        $mock->shouldReceive('setCancelAtPeriodEnd')->once()->withArgs(fn ($t, $c) => $c === false)->andReturn(new Subscription('sub_test_1'));
        $this->actingAs($user)->post(route('subscribe.resume'))->assertSessionHasNoErrors();

        $this->assertFalse((bool) $team->fresh()->stripe_cancel_at_period_end);
    }

    public function test_a_mid_period_downgrade_never_claws_back_paid_credits(): void
    {
        // The trap: a downgrade's proration invoice IS a paid invoice, so a
        // naive grantMonthlyRenewal would reset the bucket DOWN mid-period and
        // confiscate credits the customer already paid for.
        $team = Team::factory()->create([
            'plan' => Plan::Starter->value,
            'credit_balance' => 9_000,   // paid for on Growth, still unspent
        ]);

        (new CreditMeter)->raiseMonthlyAllowance($team, ['source' => 'test']);

        $this->assertSame(9_000, (int) $team->fresh()->credit_balance);
    }

    public function test_a_mid_period_upgrade_tops_the_allowance_up(): void
    {
        $team = Team::factory()->create([
            'plan' => Plan::Growth->value,
            'credit_balance' => 400,
        ]);

        (new CreditMeter)->raiseMonthlyAllowance($team, ['source' => 'test']);

        $this->assertSame(Plan::Growth->monthlyCredits(), (int) $team->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'team_id' => $team->id,
            'amount' => Plan::Growth->monthlyCredits() - 400,
            'reason' => CreditTransaction::REASON_GRANT_RENEWAL,
        ]);
    }

    public function test_an_upgrade_needing_card_authentication_sends_the_browser_to_the_invoice(): void
    {
        // EU cards routinely need 3-D Secure on the proration charge. Stripe
        // then parks the change as pending_update and hands back an invoice
        // to authenticate; the customer must be sent there, not shown an
        // error (the founder's live upgrade failed exactly this way).
        config(['billing.stripe_price.operator' => 'price_operator_m']);
        $user = $this->owner(Plan::Starter);

        $pending = Subscription::constructFrom([
            'id' => 'sub_test_1',
            'pending_update' => ['expires_at' => 1_900_000_000],
            'latest_invoice' => [
                'id' => 'in_test_pending',
                'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_test/in_test_pending',
                'payment_intent' => ['id' => 'pi_test', 'status' => 'requires_action'],
            ],
        ]);
        $this->stripeSpy()->shouldReceive('changeSubscriptionPrice')->once()->andReturn($pending);

        $response = $this->actingAs($user)
            ->from(route('billing.index'))
            ->post(route('subscribe.change', 'operator'));

        // Inertia::location → a real navigation to Stripe's hosted invoice.
        $this->assertContains($response->getStatusCode(), [302, 409]);
        $target = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $this->assertSame('https://invoice.stripe.com/i/acct_test/in_test_pending', $target);
        // The plan is NOT touched locally — the webhook does that once paid.
        $this->assertSame(Plan::Starter, $user->currentTeam->fresh()->planObject());
    }
}
