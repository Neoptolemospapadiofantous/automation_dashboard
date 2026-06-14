<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class VerificationEmailContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_mail_uses_branded_copy(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Marie']);

        $notification = new VerifyEmail;
        /** @var MailMessage $mail */
        $mail = $notification->toMail($user);

        $this->assertSame('Verify your email to activate Flowstack', $mail->subject);
        $this->assertContains('Hi Marie,', $mail->greeting ? [$mail->greeting] : array_map(fn ($l) => (string) $l, $mail->introLines));
        $this->assertSame('Verify email', $mail->actionText);
        $this->assertNotEmpty($mail->actionUrl);
        // Signed URL → /email/verify/{id}/{hash}?expires=...&signature=...
        $this->assertStringContainsString('/email/verify/', (string) $mail->actionUrl);
    }
}
