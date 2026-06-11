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
        'last_health_check_at',
        'last_health_ok',
    ];

    protected function casts(): array
    {
        return [
            'last_health_check_at' => 'datetime',
            'last_health_ok' => 'boolean',
        ];
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
