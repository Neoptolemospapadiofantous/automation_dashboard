<?php

namespace Tests\Feature;

use App\Billing\OwnKey;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\TeamProviderKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_page_reports_credits_for_a_team_without_a_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user)
            ->get(route('install.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('own_key.active', false)
                ->where('own_key.used', 0)
            );
    }

    public function test_install_page_reports_the_own_key_when_it_covers_the_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Pro->value])->save();
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $provider = app(OwnKey::class)->providerFor($agent);
        TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => $provider,
            'api_key' => 'sk-test-'.str_repeat('x', 20),
            'key_hint' => '…xxxx',
            'last_verified_at' => now(),
            'last_error' => null,
        ]);

        $this->actingAs($user)
            ->get(route('install.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('own_key.active', true)
                ->where('own_key.provider', $provider)
                ->where('own_key.cap', Plan::Pro->monthlyMessageCap())
            );
    }
}
