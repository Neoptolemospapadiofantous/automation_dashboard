<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'gid',
            'services.google.client_secret' => 'gsecret',
        ]);
    }

    private function fakeProviderUser(string $id, string $email, string $name = 'Maria Kyriakou'): void
    {
        $remote = (new SocialiteUser)->map(['id' => $id, 'email' => $email, 'name' => $name, 'nickname' => null, 'avatar' => 'https://img.example/x.png']);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($remote);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_unconfigured_provider_is_404_and_buttons_are_hidden(): void
    {
        config(['services.google.client_id' => '']);
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/microsoft')->assertNotFound();

        $this->get(route('login'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('socialProviders', []));
    }

    public function test_configured_provider_shows_its_button(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('socialProviders', ['google']));
    }

    public function test_first_time_google_user_is_registered_with_a_team_and_verified(): void
    {
        $this->fakeProviderUser('g-1', 'Maria@Example.com');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Maria Kyriakou', $user->name);
        $this->assertNotNull($user->currentTeam, 'a personal team is provisioned like a form registration');
        $this->assertSame(1, SocialAccount::query()->where('user_id', $user->id)->where('provider', 'google')->count());
    }

    public function test_existing_email_is_linked_rather_than_duplicated(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'nikos@example.com']);
        $this->fakeProviderUser('g-2', 'nikos@example.com', 'Nikos');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::query()->where('email', 'nikos@example.com')->count());
        $this->assertSame('g-2', SocialAccount::query()->where('user_id', $existing->id)->value('provider_id'));
    }

    public function test_linked_account_signs_in_even_if_the_provider_email_changed(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.com']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-3', 'email' => 'old@example.com']);
        $this->fakeProviderUser('g-3', 'renamed@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, User::query()->where('email', 'renamed@example.com')->count());
    }

    public function test_two_factor_users_are_sent_to_the_challenge_not_logged_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'tfa@example.com']);
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->fakeProviderUser('g-4', 'tfa@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_provider_without_an_email_is_refused(): void
    {
        $this->fakeProviderUser('g-5', '');

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
