<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use Searchable;

    protected $fillable = [
        'conversation_id',
        'team_id',
        'role',
        'text',
        'trace_type',
        'payload',
        'sequence',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The data indexed for search (keyword via Typesense, plus fields for
     * filtering/sorting). Embeddings for semantic search are attached by the
     * indexing pipeline when enabled.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'team_id' => (int) $this->team_id,
            'role' => (string) $this->role,
            'text' => (string) $this->text,
            'sent_at' => optional($this->sent_at)->timestamp ?? $this->created_at?->timestamp,
        ];
    }

    /**
     * Only index rows that actually carry text.
     */
    public function shouldBeSearchable(): bool
    {
        return filled($this->text);
    }
}
