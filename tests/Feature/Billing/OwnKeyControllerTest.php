<?php

namespace Tests\Feature\Billing;

use App\Billing\Plan;
use App\Models\Team;
use App\Models\TeamProviderKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The settings surface for bring-your-own-key.
 *
 * The cases worth having are the authorization ones: the plan gate must hold
 * server-side (not just in the UI), a key must be probed before it is stored,
 * and one team must never be able to touch another's credential.
 */
class OwnKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingOnPlan(Plan $plan): Team
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => $plan->value])->save();
        $this->actingAs($user);

        return $team->fresh();
    }

    public function test_the_page_offers_the_feature_on_operator_and_upsells_below_it(): void
    {
        $this->actingOnPlan(Plan::Pro);
        $this->get(route('own-key.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/OwnKey')->where('allowed', true));

        $this->actingOnPlan(Plan::Starter);
        $this->get(route('own-key.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('allowed', false));
    }

    public function test_a_plan_below_operator_cannot_store_a_key_even_by_replaying_the_request(): void
    {
        $this->actingOnPlan(Plan::Growth);

        $this->post(route('own-key.store'), [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.str_repeat('x', 30),
        ])->assertSessionHasErrors('api_key');

        $this->assertDatabaseCount('team_provider_keys', 0);
    }

    public function test_a_key_that_fails_its_probe_is_not_stored(): void
    {
        $this->actingOnPlan(Plan::Pro);
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->post(route('own-key.store'), [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.str_repeat('x', 30),
        ])->assertSessionHasErrors('api_key');

        $this->assertDatabaseCount('team_provider_keys', 0);
    }

    public function test_a_verified_key_is_stored_encrypted_with_only_a_hint(): void
    {
        $team = $this->actingOnPlan(Plan::Pro);
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        $this->post(route('own-key.store'), [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.str_repeat('x', 26).'ab7f',
        ])->assertSessionHasNoErrors();

        $key = TeamProviderKey::where('team_id', $team->id)->firstOrFail();
        $this->assertSame('…ab7f', $key->key_hint);
        $this->assertNotNull($key->last_verified_at);
        $this->assertNull($key->last_error);

        $raw = \DB::table('team_provider_keys')->where('id', $key->id)->value('api_key');
        $this->assertStringNotContainsString('sk-ant-', (string) $raw, 'the column must not hold the plaintext key');
    }

    public function test_a_team_cannot_touch_another_teams_key(): void
    {
        $victim = $this->actingOnPlan(Plan::Pro);
        $key = TeamProviderKey::create([
            'team_id' => $victim->id,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.str_repeat('x', 20),
            'key_hint' => '…xxxx',
            'last_verified_at' => now(),
        ]);

        // A different team, also on Operator, must still be refused.
        $this->actingOnPlan(Plan::Pro);

        $this->delete(route('own-key.destroy', $key->id))->assertForbidden();
        $this->post(route('own-key.verify', $key->id))->assertForbidden();
        $this->assertDatabaseCount('team_provider_keys', 1);
    }

    public function test_removing_a_key_returns_the_team_to_credit_metering(): void
    {
        $team = $this->actingOnPlan(Plan::Pro);
        $key = TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.str_repeat('x', 20),
            'key_hint' => '…xxxx',
            'last_verified_at' => now(),
        ]);

        $this->delete(route('own-key.destroy', $key->id))->assertRedirect();
        $this->assertDatabaseCount('team_provider_keys', 0);
    }
}
