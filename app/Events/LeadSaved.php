<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a lead is created or updated so every board connected to
 * the owning team patches that card in place — no reload, no polling.
 *
 * ShouldQueue: the broadcast round-trip happens on the queue worker
 * rather than blocking the request thread (matches the README's "queue
 * worker required" guidance). $afterCommit defers dispatch until the
 * surrounding DB transaction commits, so subscribers never see a row
 * that may be rolled back.
 */
class LeadSaved implements ShouldBroadcast, ShouldQueue
{
    public bool $afterCommit = true;

    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Lead $lead) {}

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
