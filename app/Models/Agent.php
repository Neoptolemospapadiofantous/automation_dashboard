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
 * One row per Voiceflow project a team has configured. A team can have many
 * agents (sales bot, support bot, …) and switches between them via
 * Team::current_agent_id.
 *
 * Credential columns use the `encrypted` cast so API keys are never stored
 * in cleartext at rest. Reading them through the model is transparent —
 * downstream services (VoiceflowService) take the decrypted string as-is.
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
     * Provisioning mode (Phase J).
     *
     * byok    — user pastes their own Voiceflow VF.DM key + project id.
     *           Default for existing rows; the original Phase 13 behaviour.
     * managed — we own the Voiceflow workspace + master project. On signup,
     *           CreateAgent clones the template environment via the Project
     *           API into a fresh per-tenant env. The agent row stores only
     *           the cloned environment id — auth + project_id come from
     *           .env at request time via VoiceflowService::forAgent.
     */
    public const MODE_BYOK = 'byok';

    public const MODE_MANAGED = 'managed';

    /**
     * runtime_mode — which conversational engine answers for this agent.
     * Default 'voiceflow' (legacy adapter; the DB default + the
     * dispatcher's match-default both encode it, so no constant is
     * needed for it). Flip to 'native' once a tester has validated the
     * Flowstack-owned runtime end-to-end for the agent — anything that
     * isn't RUNTIME_NATIVE routes to the Voiceflow adapter (defensive
     * fallback for unknown values, see RuntimeDispatcher::engineFor).
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
        'voiceflow_api_key',
        'voiceflow_project_id',
        'voiceflow_environment',
        'voiceflow_workspace_api_key',
        'webhook_secret',
        'status',
        'mode',
        'runtime_mode',
        'last_health_check_at',
        'last_health_ok',
    ];

    protected $hidden = [
        // Never let credentials end up in Inertia props by accident. UI code
        // that needs to expose them must call ->only(...) explicitly.
        'voiceflow_api_key',
        'voiceflow_workspace_api_key',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'voiceflow_api_key' => 'encrypted',
            'voiceflow_workspace_api_key' => 'encrypted',
            // webhook_secret authenticates inbound Voiceflow webhooks; storing
            // cleartext while sibling Voiceflow keys are encrypted is asymmetric
            // blast-radius. Encrypt at rest; reads are transparent via the cast.
            'webhook_secret' => 'encrypted',
            'last_health_check_at' => 'datetime',
            'last_health_ok' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            $agent->slug ??= self::generateSlug($agent->team_id);
            $agent->webhook_secret ??= Str::random(40);
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
     * Whether this agent has the minimum credentials to make API calls.
     *
     * Post-Phase-K, managed and BYOK agents look identical at the row
     * level: both store voiceflow_api_key + voiceflow_project_id. The
     * only difference is who set them — the user (BYOK) vs. the pool
     * allocator (managed). The earlier managed-specific branch (read
     * api_key + project_id from .env config) was a workaround for the
     * removed env-clone design.
     */
    public function isConfigured(): bool
    {
        // Native agents need no per-agent credentials — the engine's keys
        // are platform-level (ANTHROPIC_API_KEY / OPENAI_API_KEY) and their
        // presence is reported by AgentRuntime::health, not by this row.
        // Without this branch, native agents were locked out of the Chat
        // page, shown "Needs credentials" badges, and could never pass the
        // disabled→active lifecycle guard.
        if ($this->getAttribute('runtime_mode') === self::RUNTIME_NATIVE) {
            return true;
        }

        return ! empty($this->voiceflow_api_key) && ! empty($this->voiceflow_project_id);
    }

    public function isManaged(): bool
    {
        return $this->mode === self::MODE_MANAGED;
    }

    /**
     * Whether the workspace API (Transcripts/Analytics/KB) is enabled.
     */
    public function hasWorkspaceApi(): bool
    {
        return ! empty($this->voiceflow_workspace_api_key) && ! empty($this->voiceflow_project_id);
    }

    public function getRouteKeyName(): string
    {
        // Webhook URL contains the slug, not the numeric id. Route binding
        // resolves /api/voiceflow/lead-captured/{agent:slug} via this.
        return 'slug';
    }
}
