<?php

namespace App\Notifications;

use App\Models\Agent;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TwilioSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A chat visitor asked for a human (the agent called request_handoff, or the
 * low-confidence backstop fired). Sent to the team owner so the "a teammate
 * has been notified" promise the agent makes to the visitor is actually true.
 *
 * Carries everything the owner needs to act fast: a deep link to the exact
 * conversation (where they can take over the chat live), the visitor's last
 * message, and whether contact details were captured.
 *
 * Channels: database (bell) + mail (speed-to-human matters here even
 * more than for assignments — the visitor is live in the chat), plus SMS
 * when the recipient set a notification_phone and Twilio is configured —
 * a text is the only channel that reliably interrupts a phone in-pocket
 * while the visitor is still on the page.
 */
class HandoffRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Agent $agent,
        public string $visitorId,
        public string $reason,
        public ?int $conversationId = null,
        public string $lastMessage = '',
        public ?string $contact = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];

        if (trim((string) ($notifiable->notification_phone ?? '')) !== '' && TwilioSmsChannel::configured()) {
            $channels[] = TwilioSmsChannel::class;
        }

        // Mobile push (ntfy app on the owner's phone, urgent priority) —
        // no SMS provider needed; see NtfyChannel.
        if (NtfyChannel::configured()) {
            $channels[] = NtfyChannel::class;
        }

        return $channels;
    }

    /**
     * @return array{title: string, body: string, click: string}
     */
    public function toNtfy(object $notifiable): array
    {
        $contact = $this->contact !== null
            ? "Contact: {$this->contact}."
            : 'No contact captured yet — they are live in the chat NOW.';
        $last = trim($this->lastMessage) !== '' ? ' "'.mb_substr(trim($this->lastMessage), 0, 140).'"' : '';

        return [
            'title' => "Visitor asked for a human — {$this->agent->name}",
            'body' => trim("{$contact}{$last}"),
            'click' => $this->conversationId !== null ? url("/conversations/{$this->conversationId}") : url('/conversations'),
        ];
    }

    /**
     * One SMS-sized line: who asked, whether they're reachable, and the
     * deep link — enough to decide from a lock screen whether to jump in.
     */
    public function toSms(object $notifiable): string
    {
        $contact = $this->contact !== null ? "Contact: {$this->contact}." : 'No contact captured yet — they are live in the chat now.';
        $link = $this->conversationId !== null ? url("/conversations/{$this->conversationId}") : url('/conversations');

        return "Flowstack: a visitor on {$this->agent->name} asked for a human. {$contact} {$link}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Visitor asked for a human — {$this->agent->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("A visitor chatting with **{$this->agent->name}** asked to talk to a person.")
            ->line("**Reason:** {$this->reason}");

        if (trim($this->lastMessage) !== '') {
            $mail->line('**Their last message:** "'.mb_substr(trim($this->lastMessage), 0, 200).'"');
        }

        $mail->line($this->contact !== null
            ? "**Contact on file:** {$this->contact}"
            : '**No contact details captured yet** — the agent is asking for an email; reply in the chat while they are still on the page.');

        return $mail
            ->action(
                'Open the conversation',
                $this->conversationId !== null ? url("/conversations/{$this->conversationId}") : url('/conversations'),
            )
            ->line('Open it and reply — your messages reach the visitor live in the widget.');
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
            'conversation_id' => $this->conversationId,
            'contact' => $this->contact,
            'reason' => $this->reason,
            'message' => "Visitor asked for a human on {$this->agent->name}: {$this->reason}",
        ];
    }
}
