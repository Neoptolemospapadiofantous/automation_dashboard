<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Top-up purchase HTTP flow. DEV-MODE today (instant grant); when Phase H
 * wires Stripe Checkout these tests guard the front-end contract while the
 * grant path moves into the webhook handler. Keep them.
 */
class BillingTopUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_can_buy_a_topup_pack(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Free->value, 'credit_balance' => 500])->save();

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Medium->value])
            ->assertRedirect();

        $this->assertSame(500 + 5_000, $team->fresh()->credit_balance);
        $tx = CreditTransaction::query()->latest()->first();
        $this->assertSame(5_000, $tx->amount);
        $this->assertSame('grant_topup', $tx->reason);
        $this->assertSame('medium', $tx->meta['pack']);
        $this->assertSame(39, $tx->meta['price_usd']);
        $this->assertTrue($tx->meta['simulated_payment']);
    }

    public function test_operator_can_buy_a_topup_pack(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Pro->value, 'credit_balance' => 1_000])->save();

        $this->actingAs($user->fresh())
            ->post(route('billing.topup'), ['pack' => TopUpPack::Large->value])
            ->assertRedirect();

        $this->assertSame(1_000 + 20_000, $team->fresh()->credit_balance);
    }

    public function test_custom_plan_rejects_topup(): void
    {
        // Custom is project-based — credits are negotiated, not self-served.
        // The endpoint 403s and no credit_transaction is written.
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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pack']);
    }

    public function test_billing_index_exposes_topup_catalog_for_paying_tiers(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Free->value])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->has('topup_packs', 3)
                ->where('topup_packs.0.id', 'small')
                ->where('topup_packs.1.id', 'medium')
                ->where('topup_packs.2.id', 'large')
            );
    }

    public function test_billing_index_hides_topup_catalog_for_custom_plan(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Business->value])->save();

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('topup_packs', []));
    }
}
