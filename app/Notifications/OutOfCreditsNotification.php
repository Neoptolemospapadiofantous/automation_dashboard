<?php

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired the FIRST time an interaction is refused because the team has zero
 * credits remaining this period. Distinct from CreditBurnAlertNotification
 * (which fires at 50/80/95% pre-exhaustion). This one means "your agent
 * just refused a real customer turn."
 *
 * Idempotent via `Team.alert_thresholds_fired` — we tack on the string
 * "100" once the notification fires, and a renewal/top-up clears it.
 */
class OutOfCreditsNotification extends Notification
{
    use Queueable;

    public function __construct(public Team $team) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Your agent is paused — out of credits")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your team **{$this->team->name}** has run out of credits for this billing period.")
            ->line("Your agent is currently refusing new conversation turns. Customers see a generic 'temporarily unavailable' message until credits are restored.")
            ->action('Top up credits now', url('/billing'))
            ->line("Credits reset at the start of each billing period, or you can top up to continue immediately.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'out_of_credits',
            'team_id' => $this->team->id,
            'message' => 'Out of credits — your agent is refusing new conversations until you top up or the next billing period.',
        ];
    }
}
