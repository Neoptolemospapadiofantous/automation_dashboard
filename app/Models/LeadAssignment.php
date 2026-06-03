<?php

namespace App\Models;

use App\Enums\AssignmentStrategy;
use Database\Factories\LeadAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignment extends Model
{
    /** @use HasFactory<LeadAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'team_id',
        'agent_id',
        'assigned_to',
        'assigned_by',
        'previous_assignee',
        'strategy',
    ];

    protected function casts(): array
    {
        return [
            'strategy' => AssignmentStrategy::class,
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
