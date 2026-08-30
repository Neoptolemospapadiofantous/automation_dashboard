<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One collector run of the §3.1 findings tree.
 *
 * @property int $id
 * @property string $collector
 * @property Carbon $ts
 * @property string $overall
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 */
class AgentFinding extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['collector', 'ts', 'overall', 'payload'];

    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'payload' => 'array',
        ];
    }
}
