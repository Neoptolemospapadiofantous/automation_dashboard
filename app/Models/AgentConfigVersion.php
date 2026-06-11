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

    /**
     * The agent's live quality tier key. Unknown/absent values degrade
     * to 'standard' — old configs (pre-tiers) and bad data both land on
     * the cheap model at the cheap price, never the reverse.
     */
    public static function publishedTier(int $agentId): string
    {
        $config = static::publishedConfig($agentId);
        $tier = (string) ($config['model_tier'] ?? 'standard');

        return array_key_exists($tier, (array) config('runtime.tiers')) ? $tier : 'standard';
    }

    /**
     * Credits debited per visitor message for this agent's live tier.
     * The coupling that keeps margin intact: controllers multiply every
     * chat/embed debit by this.
     */
    public static function creditsPerMessage(int $agentId): int
    {
        $tier = static::publishedTier($agentId);

        return max(1, (int) config("runtime.tiers.{$tier}.credits_per_message", 1));
    }

    /**
     * The Anthropic model id for a tier key (engine-side resolution).
     */
    public static function modelForTier(string $tier): string
    {
        $model = (string) config("runtime.tiers.{$tier}.model", '');

        return $model !== '' ? $model : (string) config('runtime.llm.anthropic.model_default');
    }
}
