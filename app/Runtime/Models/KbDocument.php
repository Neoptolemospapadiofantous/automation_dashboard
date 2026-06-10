<?php

namespace App\Runtime\Models;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property string $title
 * @property string $source
 * @property string|null $source_url
 * @property string $raw_content
 * @property array<string, mixed>|null $metadata
 * @property int $chunk_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KbDocument extends Model
{
    protected $table = 'kb_documents';

    protected $fillable = [
        'agent_id',
        'title',
        'source',
        'source_url',
        'raw_content',
        'metadata',
        'chunk_count',
    ];

    protected $casts = [
        'metadata' => 'array',
        'chunk_count' => 'integer',
    ];

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<KbChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class, 'document_id');
    }
}
