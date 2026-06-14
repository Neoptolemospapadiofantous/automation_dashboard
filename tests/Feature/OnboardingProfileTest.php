<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_intro_page_renders(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('onboarding.intro'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Intro'));
    }

    public function test_profile_fields_saved_on_start_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('onboarding.start'), [
                'industry' => 'saas',
                'use_case' => 'lead_capture',
                'team_size' => '2-5',
                'website' => 'https://acme.example.com',
            ])
            ->assertRedirect();

        $team = $user->fresh()->currentTeam;
        $this->assertSame([
            'industry' => 'saas',
            'use_case' => 'lead_capture',
            'team_size' => '2-5',
            'website' => 'https://acme.example.com',
        ], $team->profile);
    }

    public function test_empty_profile_does_not_overwrite_existing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['profile' => ['industry' => 'agency']])->save();

        // Double-click → empty submit. Existing profile must survive.
        $this->actingAs($user)
            ->post(route('onboarding.start'), [])
            ->assertRedirect();

        $this->assertSame(['industry' => 'agency'], $team->fresh()->profile);
    }

    public function test_invalid_industry_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('onboarding.start'), [
                'industry' => 'definitely-not-a-real-industry',
            ])
            ->assertSessionHasErrors(['industry']);
    }

    public function test_invalid_use_case_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('onboarding.start'), [
                'use_case' => 'sky_diving',
            ])
            ->assertSessionHasErrors(['use_case']);
    }

    public function test_invalid_website_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('onboarding.start'), [
                'website' => 'not-a-real-url',
            ])
            ->assertSessionHasErrors(['website']);
    }

    public function test_partial_profile_merges_with_existing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['profile' => ['industry' => 'agency']])->save();

        $this->actingAs($user)
            ->post(route('onboarding.start'), ['use_case' => 'lead_capture'])
            ->assertRedirect();

        $this->assertSame([
            'industry' => 'agency',
            'use_case' => 'lead_capture',
        ], $team->fresh()->profile);
    }
}
