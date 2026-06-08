<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceflowWebhookEvent extends Model
{
    protected $fillable = [
        'agent_id',
        'team_id',
        'event_type',
        'event_id',
        'voiceflow_user_id',
        'voiceflow_session_key',
        'voiceflow_transcript_id',
        'payload',
        'signature_valid',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'bool',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
