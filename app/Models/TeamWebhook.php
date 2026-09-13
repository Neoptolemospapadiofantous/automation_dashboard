<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outbound webhook endpoint a team registered. Delivery is
 * App\Services\WebhookDispatcher → App\Jobs\DeliverWebhook.
 *
 * @property int $id
 * @property int $team_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $active
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon|null $last_delivered_at
 * @property int $failure_count
 */
class TeamWebhook extends Model
{
    /** Event names a webhook may subscribe to, with the copy the settings page shows. */
    public const EVENTS = [
        'lead.captured' => 'A new lead lands (chat capture or created by hand)',
        'handoff.requested' => 'A visitor asks for a human',
        'conversation.ended' => 'A conversation closes',
    ];

    /** After this many consecutive failures the endpoint is switched off. */
    public const MAX_FAILURES = 25;

    protected $fillable = ['team_id', 'url', 'secret', 'events', 'active'];

    /** The signing secret never serialises into a page or a log. */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function listensTo(string $event): bool
    {
        return $this->active && in_array($event, (array) $this->events, true);
    }
}
