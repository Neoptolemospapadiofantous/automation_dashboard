<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'assigned_to',
        'name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'score',
        'voiceflow_user_id',
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
            'last_contacted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
