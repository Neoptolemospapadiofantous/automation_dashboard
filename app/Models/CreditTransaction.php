<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every credit grant + consumption. Read for the billing-page
 * history view; written by CreditMeter on each transaction. Negative amount
 * = consumption, positive = grant.
 */
class CreditTransaction extends Model
{
    public const REASON_GRANT_RENEWAL = 'grant_renewal';
    public const REASON_GRANT_TOPUP = 'grant_topup';
    public const REASON_CONSUME_MESSAGE = 'consume_message';
    public const REASON_REFUND = 'refund';
    public const REASON_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'team_id',
        'agent_id',
        'amount',
        'reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'amount' => 'integer',
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
}
