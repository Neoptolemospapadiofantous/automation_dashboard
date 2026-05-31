<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a lead is created or updated so every board connected to
 * the owning team patches that card in place — no reload, no polling.
 */
class LeadSaved implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Lead $lead)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->lead->team_id);
    }

    public function broadcastAs(): string
    {
        return 'lead.saved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead' => $this->lead->loadMissing('assignee')->toArray(),
        ];
    }
}
