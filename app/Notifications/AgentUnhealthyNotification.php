<?php

namespace App\Notifications;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fires when an agent transitions from healthy → unhealthy. NOT fired
 * on every failed health probe — only on the transition, so a
 * persistently-broken agent doesn't spam the owner once per probe.
 *
 * State tracking is on the Agent: `last_health_ok` flips false; the
 * listener compares pre-event state to current and notifies only on
 * the down-transition.
 */
class AgentUnhealthyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Agent $agent,
        public string $reason = '',
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
        $mail = (new MailMessage)
            ->error()
            ->subject("Agent '{$this->agent->name}' is unhealthy")
            ->greeting("Hi {$notifiable->name},")
            ->line("The health probe for agent **{$this->agent->name}** just failed.");

        if ($this->reason !== '') {
            $mail->line("Reason: {$this->reason}");
        }

        return $mail
            ->line('Most common causes: Voiceflow credentials rotated, project deleted upstream, or temporary Voiceflow outage.')
            ->action('Open agent settings', url("/agents/{$this->agent->slug}"))
            ->line("If this persists, the easiest fix is to re-paste credentials in the agent's settings.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'agent_unhealthy',
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'agent_slug' => $this->agent->slug,
            'reason' => $this->reason,
            'message' => "Agent {$this->agent->name} health probe failed.",
        ];
    }
}
