<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

/**
 * Registration and the resend route must dispatch the verification email
 * via the *queued* notification. Sending it synchronously made the request
 * 500 whenever the mail provider was slow or rejected the recipient
 * (e.g. AWS SES sandbox). Guards against a regression to synchronous send.
 */
class VerificationEmailQueuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_notification_is_queued(): void
    {
        $this->assertTrue(
            is_subclass_of(QueuedVerifyEmail::class, ShouldQueue::class),
            'QueuedVerifyEmail must implement ShouldQueue so registration never blocks on mail.'
        );
    }

    public function test_registration_sends_queued_verification_notification(): void
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

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_resend_route_uses_queued_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect();

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }
}
