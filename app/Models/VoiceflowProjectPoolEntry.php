<?php

namespace App\Models;

use Database\Factories\VoiceflowProjectPoolEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per pre-created Voiceflow project that signup can allocate from.
 * See migration 2026_06_03_220000 for the why.
 */
class VoiceflowProjectPoolEntry extends Model
{
    /** @use HasFactory<VoiceflowProjectPoolEntryFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'voiceflow_project_pool';

    protected $fillable = [
        'voiceflow_project_id',
        'voiceflow_api_key',
        'voiceflow_workspace_api_key',
        'voiceflow_environment',
        'status',
        'assigned_to_agent_id',
        'assigned_at',
        'notes',
    ];

    protected $hidden = [
        // Mirror Agent's $hidden so credentials don't accidentally leak
        // via toArray() / Inertia. Admin tooling that needs them must
        // ->only() explicitly.
        'voiceflow_api_key',
        'voiceflow_workspace_api_key',
    ];

    protected function casts(): array
    {
        return [
            'voiceflow_api_key' => 'encrypted',
            'voiceflow_workspace_api_key' => 'encrypted',
            'assigned_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_to_agent_id');
    }
}
