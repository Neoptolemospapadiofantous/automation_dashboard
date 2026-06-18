<?php

namespace App\Models;

use App\Lifecycle\AgentStateMachine;
use App\Lifecycle\HasLifecycle;
use App\Lifecycle\StateMachine;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One conversational agent. A team can have many agents (sales bot,
 * support bot, …) and switches between them via Team::current_agent_id.
 *
 * Agents run on the native Flowstack runtime (app/Runtime) — there are no
 * per-agent credentials; the engine's keys are platform-level
 * (ANTHROPIC_API_KEY / OPENAI_API_KEY).
 *
 * @property array<string, mixed>|null $widget_config
 * @property list<string>|null $allowed_domains
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use HasLifecycle;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * Provisioning mode. 'managed' is the only mode — the platform owns
     * all engine infrastructure. The column persists for historical rows.
     */
    public const MODE_MANAGED = 'managed';

    /**
     * runtime_mode — which conversational engine answers for this agent.
     * 'native' (the Flowstack-owned runtime) is the only engine; the
     * column remains as the seam for any future engine.
     */
    public const RUNTIME_NATIVE = 'native';

    public function stateMachine(): StateMachine
    {
        return new AgentStateMachine($this);
    }

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'status',
        'mode',
        'runtime_mode',
        'auto_escalate_low_confidence',
        'widget_config',
        'allowed_domains',
        'last_health_check_at',
        'last_health_ok',
    ];

    protected function casts(): array
    {
        return [
            'auto_escalate_low_confidence' => 'boolean',
            'widget_config' => 'array',
            'allowed_domains' => 'array',
            'last_health_check_at' => 'datetime',
            'last_health_ok' => 'boolean',
        ];
    }

    /**
     * Default appearance/behavior for the embeddable widget. Operator
     * overrides (Install page) are merged over these. Brand: hard edges,
     * ink-on-ground — mirrors the marketing site.
     *
     * @var array<string, mixed>
     */
    public const WIDGET_DEFAULTS = [
        'accent_color' => '#000000',   // launcher + header background
        'text_color' => '#FFFFFF',     // contrast color on the accent
        'position' => 'right',         // 'right' | 'left'
        'launcher_text' => '',         // label beside the icon; '' = icon only
        'title' => '',                 // panel header; '' → agent name
        'subtitle' => 'AI assistant',  // panel header subline
        'avatar_url' => '',            // header avatar; '' = none
        'proactive_message' => '',     // teaser bubble copy; '' = no teaser
        'proactive_delay' => 8,        // seconds before teaser/auto-open
        'auto_open' => false,          // open the panel automatically
        'show_branding' => true,       // "Powered by Flowstack" footer
    ];

    /**
     * Resolved widget config: operator overrides merged over defaults, with
     * only known keys kept (stored junk can't leak into the loader).
     *
     * @return array<string, mixed>
     */
    public function widgetConfig(): array
    {
        $stored = is_array($this->widget_config) ? $this->widget_config : [];

        $merged = self::WIDGET_DEFAULTS;
        foreach (self::WIDGET_DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $merged[$key] = $stored[$key];
            }
        }

        // Normalize: position is an enum; delay is a sane integer.
        $merged['position'] = $merged['position'] === 'left' ? 'left' : 'right';
        $merged['proactive_delay'] = max(0, min(120, (int) $merged['proactive_delay']));
        $merged['auto_open'] = (bool) $merged['auto_open'];
        $merged['show_branding'] = (bool) $merged['show_branding'];

        return $merged;
    }

    /**
     * Host domains allowed to embed this agent. Empty = no restriction.
     *
     * @return list<string>
     */
    public function allowedDomains(): array
    {
        $domains = is_array($this->allowed_domains) ? $this->allowed_domains : [];

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim((string) $d)),
            $domains,
        ), fn (string $d) => $d !== ''));
    }

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            $agent->slug ??= self::generateSlug($agent->team_id);
        });
    }

    public static function generateSlug(int $teamId): string
    {
        do {
            $slug = 'team-'.$teamId.'-'.Str::lower(Str::random(8));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Whether this agent can serve conversations. Native agents carry no
     * per-row credentials — the platform keys' presence is reported by
     * AgentRuntime::health — so the row itself is always "configured".
     * Kept as a method because the lifecycle guard (disabled→active) and
     * the chat page both key on it.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function getRouteKeyName(): string
    {
        // Public embed URLs contain the slug, not the numeric id.
        return 'slug';
    }
}
