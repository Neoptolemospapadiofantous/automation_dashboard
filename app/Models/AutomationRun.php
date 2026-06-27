<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent → n8n automation invocation. Written before the request fires
 * (status STATUS_PENDING) and updated on completion. The unique
 * idempotency_key makes a re-issued call land on the existing row instead of
 * double-firing the webhook.
 *
 * @property int $id
 * @property int $team_id
 * @property int $agent_id
 * @property int|null $runtime_session_id
 * @property string $action
 * @property string $mode
 * @property string $status
 * @property string $idempotency_key
 * @property int $credits_charged
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error
 */
class AutomationRun extends Model
{
    public const MODE_SYNC = 'sync';

    public const MODE_ASYNC = 'async';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_OUT_OF_CREDITS = 'out_of_credits';

    protected $fillable = [
        'team_id',
        'agent_id',
        'runtime_session_id',
        'action',
        'mode',
        'status',
        'idempotency_key',
        'credits_charged',
        'http_status',
        'duration_ms',
        'request',
        'response',
        'error',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'credits_charged' => 'integer',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
    ];

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
