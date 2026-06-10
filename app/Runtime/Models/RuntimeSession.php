<?php

namespace App\Runtime\Models;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Runtime session — per-(agent, visitor) working state between turns.
 * See migration 2026_06_11_000003_create_runtime_sessions_table for the
 * design notes; this is just the Eloquent surface.
 *
 * @property int $id
 * @property int $agent_id
 * @property string $visitor_id
 * @property string $flow_state
 * @property array<string, mixed>|null $variables
 * @property Carbon|null $last_activity_at
 */
class RuntimeSession extends Model
{
    protected $table = 'runtime_sessions';

    protected $fillable = [
        'agent_id',
        'visitor_id',
        'flow_state',
        'variables',
        'last_activity_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
