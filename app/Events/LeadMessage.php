<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a single conversation message so an open lead/board view shows the
 * chat exchange ticking in live.
 * Queued so the broadcast call doesn't add latency to each chat turn.
 */
class LeadMessage implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  'user'|'agent'|'human'  $role  'human' = a team member replying
     *                                        during a takeover.
     */
    public function __construct(
        public int $teamId,
        public ?int $leadId,
        public string $role,
        public string $text,
        public string $at,
        public ?int $conversationId = null,
    ) {}

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
            'conversation_id' => $this->conversationId,
            'role' => $this->role,
            'text' => $this->text,
            'at' => $this->at,
        ];
    }
}
