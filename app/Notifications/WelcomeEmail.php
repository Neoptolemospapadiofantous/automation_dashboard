<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent the first time a user registers. Sets expectations + gives them
 * an entry point to the most-valuable part of the product (the chat
 * panel) so they're not staring at an empty dashboard.
 *
 * Queued on the dedicated "mail" queue so registration never blocks on
 * mail-provider latency and the send is isolated from other work.
 */
class WelcomeEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
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
        return (new MailMessage)
            ->subject('Welcome to Flowstack')
            ->greeting("Hey {$notifiable->name},")
            ->line('Thanks for signing up. Your agent is set up and ready to capture leads.')
            ->line('A few places to start:')
            ->line('• **Try the chat panel** — see what your agent says.')
            ->line('• **Knowledge base** — paste in your FAQs so the agent can answer them.')
            ->line('• **Leads board** — captures land here as soon as someone qualifies.')
            ->action('Open dashboard', url('/dashboard'))
            ->line("Reply to this email if anything's confusing. We read every reply.");
    }
}
