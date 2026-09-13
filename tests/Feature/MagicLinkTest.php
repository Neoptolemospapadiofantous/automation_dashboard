<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_sends_a_link_to_an_existing_user_and_says_the_same_thing_either_way(): void
    {
        Notification::fake();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'maria@example.com']);

        $this->from(route('login'))->post(route('magic-link.send'), ['email' => 'MARIA@example.com'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        Notification::assertSentTo($user, MagicLinkNotification::class);

        $this->from(route('login'))->post(route('magic-link.send'), ['email' => 'nobody@example.com'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        Notification::assertCount(1);
    }

    public function test_link_signs_in_exactly_once(): void
    {
        Notification::fake();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'maria@example.com', 'email_verified_at' => null]);

        $this->post(route('magic-link.send'), ['email' => 'maria@example.com']);
        $url = null;
        Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $n) use (&$url) {
            $url = $n->url;

            return true;
        });
        $this->assertNotNull($url);

        $this->get($url)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);

        // Second click: the nonce is gone.
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_tampered_or_unsigned_link_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->get('/magic-link/'.$user->id.'?nonce=abc')->assertForbidden();
        $this->assertGuest();
    }
}
