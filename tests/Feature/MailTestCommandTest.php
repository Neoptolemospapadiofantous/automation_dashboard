<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_rejects_invalid_email(): void
    {
        $this->artisan('mail:test', ['to' => 'not-an-email'])
            ->expectsOutputToContain('Not a valid email')
            ->assertExitCode(2); // Symfony INVALID
    }

    public function test_sends_to_valid_recipient_through_configured_mailer(): void
    {
        // Default test config is the array driver — Mail::raw() writes to
        // it without throwing, which is the success contract we need.
        $this->artisan('mail:test', ['to' => 'ops@example.com'])
            ->expectsOutputToContain('Sent.')
            ->assertExitCode(0);
    }

    public function test_warns_when_mailer_is_log(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['to' => 'ops@example.com'])
            ->expectsOutputToContain('MAIL_MAILER=log')
            ->assertExitCode(0);
    }
}
