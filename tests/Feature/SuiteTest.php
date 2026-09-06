<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Models\ModuleInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_page_lists_both_lines_and_gates_by_plan(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('suite.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Suite/Index')
                ->where('plan.key', Plan::Free->value)
                ->has('modules', count(config('suite.modules')))
                // A Free team sees the chat as on-plan and the own-key module as gated.
                ->where('modules', fn ($modules) => collect($modules)
                    ->firstWhere('key', 'chat')['on_plan'] === true
                    && collect($modules)->firstWhere('key', 'own_key')['on_plan'] === false
                    && collect($modules)->firstWhere('key', 'own_key')['min_plan_label'] === Plan::Growth->label()
                )
                ->where('studio_url', config('suite.studio_url'))
            );
    }

    public function test_growth_team_has_the_own_key_module_on_plan(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['plan' => Plan::Growth->value])->save();

        $this->actingAs($user)
            ->get(route('suite.index'))
            ->assertInertia(fn ($page) => $page
                ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'own_key')['on_plan'] === true)
            );
    }

    public function test_requesting_a_coming_module_is_recorded_once_per_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $this->actingAs($user)->post(route('suite.request'), ['module' => 'whatsapp'])->assertRedirect();
        $this->actingAs($user)->post(route('suite.request'), ['module' => 'whatsapp'])->assertRedirect();

        $this->assertSame(1, ModuleInterest::where('team_id', $team->id)->where('module_key', 'whatsapp')->count());
        $this->assertSame($user->id, ModuleInterest::first()?->user_id);

        // The page reflects it.
        $this->actingAs($user)
            ->get(route('suite.index'))
            ->assertInertia(fn ($page) => $page
                ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'whatsapp')['requested'] === true)
            );
    }

    public function test_live_studio_and_unknown_modules_cannot_be_requested(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach (['chat', 'studio_leak_report', 'teleportation'] as $key) {
            $this->actingAs($user)
                ->from(route('suite.index'))
                ->post(route('suite.request'), ['module' => $key])
                ->assertSessionHasErrors('module');
        }

        $this->assertSame(0, ModuleInterest::count());
    }

    public function test_interest_is_scoped_to_the_team(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();

        $this->actingAs($a)->post(route('suite.request'), ['module' => 'booking']);

        $this->actingAs($b)
            ->get(route('suite.index'))
            ->assertInertia(fn ($page) => $page
                ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'booking')['requested'] === false)
            );
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('suite.index'))->assertRedirect(route('login'));
    }
}
