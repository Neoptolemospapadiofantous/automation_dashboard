<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\BillingPortal\Session;
use Tests\TestCase;

class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_to_stripe_portal_for_subscribed_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['stripe_customer_id' => 'cus_test_subscribed'])->save();

        $sessionStub = Session::constructFrom([
            'id' => 'bps_test_123',
            'url' => 'https://billing.stripe.com/p/session/test_xyz',
        ]);
        $this->mock(StripeClient::class, function (MockInterface $mock) use ($sessionStub): void {
            $mock->shouldReceive('createBillingPortalSession')->once()->andReturn($sessionStub);
        });

        $this->actingAs($user->fresh())
            ->post(route('billing.portal'))
            ->assertRedirect('https://billing.stripe.com/p/session/test_xyz');
    }

    public function test_returns_friendly_error_when_team_has_no_stripe_customer(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['stripe_customer_id' => null])->save();

        $this->actingAs($user->fresh())
            ->from(route('billing.index'))
            ->post(route('billing.portal'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors(['portal']);
    }

    public function test_handles_stripe_failure_gracefully(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['stripe_customer_id' => 'cus_test_unconfigured'])->save();

        $this->mock(StripeClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createBillingPortalSession')->andThrow(
                new \RuntimeException('Customer Portal not configured'),
            );
        });

        $this->actingAs($user->fresh())
            ->from(route('billing.index'))
            ->post(route('billing.portal'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors(['portal']);
    }

    public function test_requires_authentication(): void
    {
        $this->post(route('billing.portal'))->assertRedirect(route('login'));
    }
}
