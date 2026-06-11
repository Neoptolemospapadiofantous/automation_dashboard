<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved version of an agent's operator-editable behavior config.
 * See the migration for the lifecycle (draft → published → archived)
 * and the config shape. The FlowExecutor injects the PUBLISHED version's
 * config into every turn; drafts are invisible to the engine.
 *
 * @property int $id
 * @property int $agent_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $config
 * @property Carbon|null $published_at
 */
class AgentConfigVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'agent_id',
        'version',
        'status',
        'config',
        'published_at',
    ];

    protected $casts = [
        'config' => 'array',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * The live config for an agent, or null when nothing is published.
     * One indexed query — called by the engine on every turn.
     *
     * @return array<string, mixed>|null
     */
    public static function publishedConfig(int $agentId): ?array
    {
        $row = static::query()
            ->where('agent_id', $agentId)
            ->where('status', self::STATUS_PUBLISHED)
            ->first();

        return $row?->config;
    }
}
