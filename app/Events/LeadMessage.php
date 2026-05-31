<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a single conversation message so an open lead/board view shows the
 * Voiceflow exchange ticking in live.
 */
class LeadMessage implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  'user'|'agent'  $role
     */
    public function __construct(
        public int $teamId,
        public ?int $leadId,
        public string $role,
        public string $text,
        public string $at,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->teamId);
    }

    public function broadcastAs(): string
    {
        return 'lead.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'role' => $this->role,
            'text' => $this->text,
            'at' => $this->at,
        ];
    }
}
