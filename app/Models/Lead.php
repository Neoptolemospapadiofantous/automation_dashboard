<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Lifecycle\HasLifecycle;
use App\Lifecycle\LeadStateMachine;
use App\Lifecycle\StateMachine;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use HasLifecycle;

    public function stateMachine(): StateMachine
    {
        return new LeadStateMachine($this);
    }

    protected $fillable = [
        'team_id',
        'agent_id',
        'assigned_to',
        'name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'score',
        'score_breakdown',
        'visitor_id',
        'captured',
        'notes',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'captured' => 'array',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'last_contacted_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Conversations captured against this lead. Powers the LeadCard's
     * conversation-count chip and the `/conversations?lead_id=X` cross-link
     * from the kanban → transcript history.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Restrict the query to a single agent. Phase G's per-agent page
     * scoping uses this from LeadController::index + DashboardController.
     *
     * Passing null returns an empty result set (not "all"). The SaaS
     * model is "one current agent at a time" — a team with no current
     * agent is mid-onboarding and shouldn't be seeing leads at all.
     */
    public function scopeForAgent(Builder $query, ?int $agentId): Builder
    {
        if ($agentId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('agent_id', $agentId);
    }
}
