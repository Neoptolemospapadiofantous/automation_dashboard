<?php

namespace App\Models;

use App\Runtime\LLM\LlmRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Merge a partial config patch into the agent's single draft, creating
     * the draft (seeded from the published config) if none exists. Used by
     * the behavior editor and the Actions editor — two pages that write
     * disjoint keys of the SAME draft, so each must preserve the other's
     * keys instead of replacing the whole config. Row-locked so concurrent
     * saves can't lose a key or duplicate the draft.
     *
     * @param  array<string, mixed>  $patch
     */
    public static function patchDraft(int $agentId, array $patch): void
    {
        DB::transaction(function () use ($agentId, $patch): void {
            $draft = static::query()
                ->where('agent_id', $agentId)
                ->where('status', self::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($draft) {
                $draft->update(['config' => array_merge($draft->config ?? [], $patch)]);

                return;
            }

            $base = static::publishedConfig($agentId) ?? [];

            static::create([
                'agent_id' => $agentId,
                'version' => (int) static::query()->where('agent_id', $agentId)->max('version') + 1,
                'status' => self::STATUS_DRAFT,
                'config' => array_merge($base, $patch),
            ]);
        });
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
     *
     * Provider-availability safeguard: an agent pinned to a tier whose
     * provider has no key (e.g. it was retired) would otherwise go dark on
     * every turn. When that happens, degrade to the funded default tier so
     * the agent keeps answering — but only when the default is actually
     * reachable, so we never trade one dead provider for another.
     */
    public static function publishedTier(int $agentId): string
    {
        $config = static::publishedConfig($agentId);
        $tier = (string) ($config['model_tier'] ?? self::defaultTier());
        $tier = self::LEGACY_TIER_ALIASES[$tier] ?? $tier;

        if (! array_key_exists($tier, (array) config('runtime.tiers'))) {
            return self::defaultTier();
        }

        if (! self::tierProviderAvailable($tier)) {
            $default = self::defaultTier();
            if ($default !== $tier && self::tierProviderAvailable($default)) {
                return $default;
            }
        }

        return $tier;
    }

    /**
     * Whether the LLM provider behind a tier has a configured API key.
     */
    protected static function tierProviderAvailable(string $tier): bool
    {
        $provider = (string) config("runtime.tiers.{$tier}.provider", 'anthropic');

        return LlmRouter::providerAvailable($provider);
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
