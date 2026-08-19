<?php

namespace App\Notifications;

use App\Models\Agent;
use App\Notifications\Channels\CallMeBotTelegramCallChannel;
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
 * more than for assignments — the visitor is live in the chat), plus a real
 * VOICE CALL to the founder's phone (CallMeBot Telegram Call API) when
 * configured — a ringing call is the one alert that interrupts
 * everything while the visitor is still on the page.
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

        // Ring the founder's phone (free CallMeBot Telegram call) — see
        // the channel class for why a call is the chosen phone layer.
        if (CallMeBotTelegramCallChannel::configured()) {
            $channels[] = CallMeBotTelegramCallChannel::class;
        }

        return $channels;
    }

    /**
     * What the synthesized voice says on the call — short and ≤256 chars
     * (the Telegram text copy carries the same line; details live in the
     * email and dashboard).
     */
    public function toCallText(object $notifiable): string
    {
        $contact = $this->contact !== null ? 'Contact details are on file.' : 'No contact captured — they are live in the chat now.';

        return "Flowstack alert. A visitor on {$this->agent->name} is asking for a human. {$contact} Open the dashboard to take over.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("🚨 Visitor needs a HUMAN right now — {$this->agent->name}")
            // Max mail priority so phone clients surface it prominently —
            // the owner's requirement is that a human-request is NEVER missed.
            ->priority(1)
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
