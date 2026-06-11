<?php

namespace App\Notifications;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A chat visitor asked for a human (the agent called request_handoff).
 * Sent to the team owner so the "a teammate has been notified" promise
 * the agent makes to the visitor is actually true.
 *
 * Channels: database (bell) + mail (speed-to-human matters here even
 * more than for assignments — the visitor is live in the chat).
 */
class HandoffRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Agent $agent,
        public string $visitorId,
        public string $reason,
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
        return (new MailMessage)
            ->subject("Visitor asked for a human — {$this->agent->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("A visitor chatting with **{$this->agent->name}** asked to talk to a person.")
            ->line("**Reason:** {$this->reason}")
            ->action('Open conversations', url('/conversations'))
            ->line('They are (or were just) live in the chat — fast follow-up wins these.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'visitor_id' => $this->visitorId,
            'reason' => $this->reason,
            'message' => "Visitor asked for a human on {$this->agent->name}: {$this->reason}",
        ];
    }
}
