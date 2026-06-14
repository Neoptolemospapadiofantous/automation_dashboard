<?php

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a team's `credit_balance` crosses a usage threshold this
 * billing period. Idempotent via Team.alert_thresholds_fired.
 *
 * Channels:
 *  - database: feeds the bell UI
 *  - mail:     reaches the owner's inbox so they don't miss it during off-hours
 */
class CreditBurnAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Team $team,
        public int $thresholdPercent,
        public int $creditsRemaining,
        public int $monthlyGrant,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $remainingPct = $this->monthlyGrant > 0
            ? (int) floor(($this->creditsRemaining / $this->monthlyGrant) * 100)
            : 0;

        $mail = (new MailMessage)
            ->subject("Heads up: you've used {$this->thresholdPercent}% of this month's credits")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your team **{$this->team->name}** has used {$this->thresholdPercent}% of its monthly credits.")
            ->line("Remaining: **{$this->creditsRemaining}** of {$this->monthlyGrant} ({$remainingPct}%).");

        if ($this->thresholdPercent >= 95) {
            $mail->line("**You're almost out.** Top up to avoid chat interruptions.");
        } elseif ($this->thresholdPercent >= 80) {
            $mail->line('Consider topping up soon — at this pace you may run out before renewal.');
        }

        return $mail
            ->action('Top up credits', url('/billing'))
            ->line('Credits reset at the start of each billing period.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'credit_burn_alert',
            'team_id' => $this->team->id,
            'threshold_percent' => $this->thresholdPercent,
            'credits_remaining' => $this->creditsRemaining,
            'monthly_grant' => $this->monthlyGrant,
            'message' => sprintf(
                '%d%% of monthly credits used — %d remaining of %d.',
                $this->thresholdPercent,
                $this->creditsRemaining,
                $this->monthlyGrant,
            ),
        ];
    }
}
