<?php

namespace App\Runtime\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A visitor question the knowledge base could not answer confidently —
 * the raw material of the "fill these KB holes" panel on the Knowledge
 * page. One row per (agent, normalized question); repeats bump
 * asked_count so the panel naturally ranks by demand.
 *
 * @property int $agent_id
 * @property string $question
 * @property string $question_hash
 * @property float $top_score
 * @property int $asked_count
 * @property Carbon $last_asked_at
 */
class KbGap extends Model
{
    protected $fillable = [
        'agent_id',
        'question',
        'question_hash',
        'top_score',
        'asked_count',
        'last_asked_at',
    ];

    protected $casts = [
        'top_score' => 'float',
        'last_asked_at' => 'datetime',
    ];

    /**
     * Record a low-confidence question. Dedupes on a normalized hash so
     * "What are your prices?" asked ten times is one row with count 10.
     * Keeps the highest score seen — the closest the KB ever got.
     */
    public static function record(int $agentId, string $question, float $topScore): void
    {
        $question = trim($question);
        if ($question === '') {
            return;
        }

        $question = mb_substr($question, 0, 500);
        $hash = sha1(mb_strtolower(preg_replace('/\s+/', ' ', $question) ?? $question));

        $gap = static::query()->firstOrNew([
            'agent_id' => $agentId,
            'question_hash' => $hash,
        ]);

        $gap->question = $question;
        $gap->top_score = max((float) ($gap->top_score ?? 0), $topScore);
        $gap->asked_count = $gap->exists ? $gap->asked_count + 1 : 1;
        $gap->last_asked_at = now();
        $gap->save();
    }
}
