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

    /**
     * Whether this agent has the minimum credentials to make API calls.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->voiceflow_api_key) && ! empty($this->voiceflow_project_id);
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
