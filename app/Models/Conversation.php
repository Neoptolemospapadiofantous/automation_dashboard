<?php

namespace App\Models;

use App\Lifecycle\ConversationStateMachine;
use App\Lifecycle\HasLifecycle;
use App\Lifecycle\StateMachine;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $meta
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use HasLifecycle;

    /** Visitor satisfaction ratings, worst → best. */
    public const RATINGS = ['bad', 'ok', 'good'];

    public function stateMachine(): StateMachine
    {
        return new ConversationStateMachine($this);
    }

    protected $fillable = [
        'team_id',
        'agent_id',
        'lead_id',
        'visitor_id',
        'visitor_token',
        'session_key',
        'transcript_id',
        'channel',
        'status',
        'rating',
        'feedback_comment',
        'rated_at',
        'message_count',
        'started_at',
        'ended_at',
        'last_message_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'rated_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Restrict to a single agent — see Lead::scopeForAgent for semantics.
     * Null agentId returns no rows.
     */
    public function scopeForAgent(Builder $query, ?int $agentId): Builder
    {
        if ($agentId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('agent_id', $agentId);
    }
}
