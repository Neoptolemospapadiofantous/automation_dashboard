<?php

namespace App\Runtime\Models;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $agent_id
 * @property int $position
 * @property string $content
 * @property list<float> $embedding
 * @property string $embedding_model
 * @property array<string, mixed>|null $metadata
 */
class KbChunk extends Model
{
    protected $table = 'kb_chunks';

    protected $fillable = [
        'document_id',
        'agent_id',
        'position',
        'content',
        'embedding',
        'embedding_model',
        'metadata',
    ];

    protected $casts = [
        'position' => 'integer',
        'embedding' => 'array', // list<float>
        'metadata' => 'array',
    ];

    /** @return BelongsTo<KbDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'document_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
