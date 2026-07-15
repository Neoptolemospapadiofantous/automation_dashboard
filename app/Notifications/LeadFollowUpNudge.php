<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This lead is going cold" — sent once per lead by leads:follow-up-nudges
 * when a captured lead has never been contacted after the grace window.
 * Goes to the assigned rep when there is one, else the team owner.
 */
class LeadFollowUpNudge extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->lead->name ?? '(no name)';
        $age = $this->lead->created_at?->diffForHumans() ?? 'a while ago';

        $mail = (new MailMessage)
            ->subject("Lead going cold: {$name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("**{$name}** was captured {$age} and nobody has reached out yet.");

        if ($this->lead->email) {
            $mail->line("**Email:** {$this->lead->email}");
        }
        if ($this->lead->phone) {
            $mail->line("**Phone:** {$this->lead->phone}");
        }

        return $mail
            ->action('Open in dashboard', url('/leads?focus='.$this->lead->id))
            ->line('Hit "Mark contacted" on the lead once you\'ve reached out.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'message' => 'Lead not contacted yet: '.$this->lead->name,
        ];
    }
}
