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
     * Legacy tier keys from before the full model lineup. Published rows
     * saved under the old names keep working without a data migration.
     */
    private const LEGACY_TIER_ALIASES = [
        'standard' => 'haiku',
        'enhanced' => 'sonnet',
    ];

    /**
     * Hard floor: the cheapest known tier, guaranteed to exist. Used as the
     * ultimate fallback when even the configured default_tier is invalid.
     */
    public const DEFAULT_TIER = 'haiku';

    /**
     * The out-of-box tier for new/unconfigured agents. Env-driven
     * (RUNTIME_DEFAULT_TIER) so prod can point it at whichever provider is
     * actually funded; falls back to the hard floor if misconfigured.
     */
    public static function defaultTier(): string
    {
        $tier = (string) config('runtime.default_tier', self::DEFAULT_TIER);
        $tier = self::LEGACY_TIER_ALIASES[$tier] ?? $tier;

        return array_key_exists($tier, (array) config('runtime.tiers')) ? $tier : self::DEFAULT_TIER;
    }

    /**
     * The agent's live quality tier key. Legacy keys alias to their
     * lineup equivalent; unknown/absent values degrade to the configured
     * default tier — bad data lands on the funded default, never a dead one.
     */
    public static function publishedTier(int $agentId): string
    {
        $config = static::publishedConfig($agentId);
        $tier = (string) ($config['model_tier'] ?? self::defaultTier());
        $tier = self::LEGACY_TIER_ALIASES[$tier] ?? $tier;

        return array_key_exists($tier, (array) config('runtime.tiers')) ? $tier : self::defaultTier();
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
