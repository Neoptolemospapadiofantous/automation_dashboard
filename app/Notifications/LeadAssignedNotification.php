<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to a rep when a lead is delegated to them. Stored in the database so the
 * rep can see their pending leads; the bell UI polls/refreshes via Inertia.
 */
class LeadAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'company' => $this->lead->company,
            'score' => $this->lead->score,
            'message' => 'You were assigned a new lead: '.$this->lead->name,
        ];
    }
}
