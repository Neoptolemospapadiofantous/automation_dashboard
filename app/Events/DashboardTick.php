<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A heartbeat event used to prove the real-time pipeline end to end.
 *
 * It broadcasts on a public "dashboard" channel so any connected browser
 * receives an instant tick without authentication. Later phases broadcast
 * domain events (LeadCreated, LeadAssigned, ...) on private/presence channels.
 *
 * Queued so the Pusher HTTP call doesn't block the request.
 */
class DashboardTick implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $count,
        public string $message,
        public string $at,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('dashboard');
    }

    /**
     * The event name the frontend listens for.
     */
    public function broadcastAs(): string
    {
        return 'tick';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'count' => $this->count,
            'message' => $this->message,
            'at' => $this->at,
        ];
    }
}
