<?php

namespace App\Models;

use App\Lifecycle\ConversationStateMachine;
use App\Lifecycle\HasLifecycle;
use App\Lifecycle\StateMachine;
use App\Services\WebhookDispatcher;
use App\Support\Tags;
use App\Support\WebhookPayloads;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $team_id
 * @property int $agent_id
 * @property string $visitor_id
 * @property int|null $lead_id
 * @property array<string, mixed>|null $meta
 * @property list<string>|null $labels
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use HasLifecycle;

    /** Visitor satisfaction ratings, worst → best. */
    public const RATINGS = ['bad', 'ok', 'good'];

    protected static function booted(): void
    {
        // Every way a chat ends — the model's end_session, the auto-close
        // sweep, the teammate's Close button — lands on status='ended'
        // through save(), so the conversation.ended webhook is emitted here
        // rather than at each call site. Best-effort: a queue hiccup must
        // never break the save.
        static::updated(function (Conversation $conversation): void {
            if ($conversation->wasChanged('status') && $conversation->getAttribute('status') === 'ended') {
                $team = $conversation->team;
                if ($team instanceof Team) {
                    rescue(fn () => app(WebhookDispatcher::class)->dispatch(
                        $team,
                        'conversation.ended',
                        WebhookPayloads::conversation($conversation),
                    ), report: false);
                }
            }
        });
    }

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
        'labels',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'labels' => 'array',
            'rated_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Labels are normalised on the way in exactly like lead tags.
     *
     * @param  mixed  $value
     */
    public function setLabelsAttribute($value): void
    {
        $labels = Tags::normalize($value);
        $this->attributes['labels'] = $labels === [] ? null : json_encode($labels);
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
