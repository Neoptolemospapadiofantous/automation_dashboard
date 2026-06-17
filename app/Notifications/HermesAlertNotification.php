<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operator alert for Hermes CRITICAL/FAIL findings — leaked secret, APP_DEBUG
 * in prod, dependency CVE, disk full, DB unreachable, etc.
 *
 * Dispatched by `hermes:alert` ONLY when the finding-set changes, so a standing
 * condition (e.g. disk at 91%) doesn't email on every 6-hourly run.
 *
 * Queued on the dedicated "mail" queue — the same lane as transactional auth
 * mail — so it rides the working SES stack and never blocks the scheduler.
 * Requires the Email Worker daemon (`queue:work --queue=mail`) to deliver.
 */
class HermesAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{collector:string,severity:string,check:string,detail:string}>  $findings
     */
    public function __construct(
        public array $findings,
        public string $appEnv,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->findings);

        $mail = (new MailMessage)
            ->error()
            ->subject("🚨 Hermes: {$count} CRITICAL/FAIL finding(s) on {$this->appEnv}")
            ->line("Hermes detected {$count} CRITICAL/FAIL finding(s) on **{$this->appEnv}**:");

        foreach ($this->findings as $f) {
            $mail->line("• **[{$f['severity']}] {$f['collector']} / {$f['check']}** — {$f['detail']}");
        }

        return $mail
            ->line('Run `composer hermes-status` on the server for the full report.')
            ->line('This alert re-sends only when the set of active findings changes.');
    }
}
