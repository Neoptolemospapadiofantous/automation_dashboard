<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Notifications\WelcomeEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

/**
 * Transactional auth emails (verify, welcome, password-reset) must be
 * QUEUED and routed to the dedicated "mail" queue. Sending them
 * synchronously made the originating request 500 whenever the mail
 * provider was slow or rejected the recipient (e.g. AWS SES sandbox).
 * Guards against a regression to synchronous send / wrong queue.
 */
class VerificationEmailQueuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactional_notifications_are_queued(): void
    {
        foreach ([QueuedVerifyEmail::class, WelcomeEmail::class, QueuedResetPassword::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, ShouldQueue::class),
                "{$class} must implement ShouldQueue so requests never block on mail."
            );
        }
    }

    public function test_registration_queues_verify_and_welcome_on_mail_queue(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');
        }

        Notification::fake();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'queued@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'queued@example.com')->firstOrFail();

        Notification::assertSentTo($user, QueuedVerifyEmail::class, fn ($n) => $n->queue === 'mail');
        Notification::assertSentTo($user, WelcomeEmail::class, fn ($n) => $n->queue === 'mail');
    }

    public function test_resend_route_uses_queued_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect();

        Notification::assertSentTo($user, QueuedVerifyEmail::class, fn ($n) => $n->queue === 'mail');
    }

    public function test_password_reset_is_queued_on_mail_queue(): void
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->markTestSkipped('Password resets are not enabled.');
        }

        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, fn ($n) => $n->queue === 'mail');
    }
}
