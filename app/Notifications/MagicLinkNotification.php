<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-click sign-in link (MagicLinkController). Mail only, queued so the
 * request never waits on the mail provider. Not subject to notification
 * preferences: the user just asked for it.
 */
class MagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public int $ttlMinutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Flowstack sign-in link')
            ->greeting("Hi {$notifiable->name},")
            ->line('Click the button to sign in. No password needed.')
            ->action('Sign in to Flowstack', $this->url)
            ->line("The link works once and expires in {$this->ttlMinutes} minutes. If you did not ask for it, ignore this email — nothing happens without the click.");
    }
}
