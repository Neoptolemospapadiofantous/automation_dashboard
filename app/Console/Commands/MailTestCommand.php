<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Send a one-off probe email to verify SMTP / SES / Postmark / etc. is wired up.
 *
 *   php artisan mail:test you@example.com
 *
 * Prints the resolved driver + from-address so the operator can confirm the
 * environment is the one they expect, then attempts the send. On failure,
 * shows the SDK error message inline.
 */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test {to : Recipient email address}';

    protected $description = 'Send a test email through the configured mailer to verify SMTP / SES / etc. is wired up.';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email: {$to}");

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');

        $this->components->info("Mailer:       {$mailer}");
        $this->components->info("From:         {$fromName} <{$from}>");
        $this->components->info("To:           {$to}");
        $this->newLine();

        try {
            Mail::raw(
                'Mail delivery test from '.config('app.name').' at '.now()->toIso8601String()."\n\nIf you're reading this, your SMTP / SES configuration is working.",
                function (Message $msg) use ($to): void {
                    $msg->to($to)
                        ->subject('Flowstack — Mail delivery test');
                }
            );
        } catch (\Throwable $e) {
            $this->components->error('Send failed.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sent. Check the inbox (and spam folder).');

        if ($mailer === 'log') {
            $this->newLine();
            $this->warn('MAIL_MAILER=log → no real email was sent.');
            $this->line('Tail storage/logs/laravel.log to see what would have been delivered.');
        }

        return self::SUCCESS;
    }
}
