<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the team owner the moment the chat agent captures a lead.
 *
 * Assignment already notifies the assigned rep (LeadAssignedNotification),
 * but most runtime-captured leads start UNASSIGNED — before this existed
 * they sat silent until someone happened to open the dashboard, which is
 * the opposite of the speed-to-lead pitch.
 *
 * Channels:
 *  - database: feeds the bell icon UI for in-app awareness
 *  - mail:     owners live in Gmail; a hot lead can't wait for a refresh
 */
class LeadCapturedNotification extends Notification implements ShouldQueue
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
            ->subject("Your agent captured a lead: {$name}")
            ->greeting("Hi {$notifiable->name},")
            ->line('Your chat agent just captured a new lead.');

        // company / email / phone are visitor-authored — neutralize markdown so
        // a captured "name" like "[click](https://phish)" can't render as a
        // live link in the owner's email.
        if ($score !== null) {
            $mail->line("**Score: {$score}/100**");
        }
        if ($this->lead->company) {
            $mail->line('**Company:** '.MailText::plain((string) $this->lead->company));
        }
        if ($this->lead->email) {
            $mail->line('**Email:** '.MailText::plain((string) $this->lead->email));
        }
        if ($this->lead->phone) {
            $mail->line('**Phone:** '.MailText::plain((string) $this->lead->phone));
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
            'message' => 'Your agent captured a new lead: '.$this->lead->name,
        ];
    }
}
