<?php

namespace Tests\Feature;

use App\Models\WaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-launch waitlist capture (coming-soon page). The POST endpoint is
 * allowlisted in the ComingSoon gate + CSRF-exempt, so it works while the
 * app is gated.
 */
class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_gated_coming_soon_page_shows_the_waitlist_form(): void
    {
        config(['app.coming_soon' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertSee('Join the waitlist');
    }

    public function test_valid_email_is_stored_and_confirmed(): void
    {
        $this->post('/waitlist', ['email' => 'Founder@Example.com'])
            ->assertOk()
            ->assertSee('on the list');

        $this->assertDatabaseHas('waitlist_signups', [
            'email' => 'founder@example.com', // normalised to lowercase
            'source' => 'coming_soon',
        ]);
    }

    public function test_duplicate_email_does_not_create_a_second_row(): void
    {
        $this->post('/waitlist', ['email' => 'dupe@example.com'])->assertOk();
        $this->post('/waitlist', ['email' => 'dupe@example.com'])->assertOk();

        $this->assertSame(1, WaitlistSignup::where('email', 'dupe@example.com')->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->post('/waitlist', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertSee('valid email');

        $this->assertSame(0, WaitlistSignup::count());
    }

    public function test_honeypot_silently_drops_bots(): void
    {
        $this->post('/waitlist', ['email' => 'bot@example.com', 'company' => 'spam co'])
            ->assertOk()
            ->assertSee('on the list');

        $this->assertSame(0, WaitlistSignup::count());
    }
}
