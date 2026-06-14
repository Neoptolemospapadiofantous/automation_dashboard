<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a rep when a lead is delegated to them.
 *
 * Channels:
 *  - database: feeds the bell icon UI for in-app awareness
 *  - mail:     reps live in Gmail; speed-to-lead matters more than
 *              waiting for them to refresh the dashboard
 */
class LeadAssignedNotification extends Notification
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
        $score = $this->lead->score ?? null;
        $name = $this->lead->name ?? '(no name)';

        $mail = (new MailMessage)
            ->subject("New lead assigned: {$name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("You've been assigned a new lead in Flowstack.");

        if ($score !== null) {
            $mail->line("**Score: {$score}/100**");
        }

        if ($this->lead->company) {
            $mail->line("**Company:** {$this->lead->company}");
        }
        if ($this->lead->email) {
            $mail->line("**Email:** {$this->lead->email}");
        }
        if ($this->lead->phone) {
            $mail->line("**Phone:** {$this->lead->phone}");
        }

        return $mail
            ->action('Open in dashboard', url('/leads?focus='.$this->lead->id))
            ->line('Reach out fast — leads contacted within 5 minutes are 7x more likely to qualify.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'company' => $this->lead->company,
            'score' => $this->lead->score,
            'message' => 'You were assigned a new lead: '.$this->lead->name,
        ];
    }
}
