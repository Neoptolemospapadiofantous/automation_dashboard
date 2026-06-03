<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a lead is removed so connected boards drop the card live.
 * Queued (after-commit) so the Pusher round-trip doesn't block the
 * deletion request and never fires for rolled-back deletes.
 */
class LeadDeleted implements ShouldBroadcast, ShouldQueue
{
    public bool $afterCommit = true;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $leadId, public int $teamId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->teamId);
    }

    public function broadcastAs(): string
    {
        return 'lead.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->leadId];
    }
}
