<?php

namespace App\Runtime\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $now = now();

        // Atomic upsert on the (agent_id, question_hash) unique index: two
        // visitors asking the identical question at the same instant both land
        // safely — no check-then-insert race, no lost increments, no unique
        // violation to rescue. On conflict we bump the count, keep the best
        // score seen, and refresh the timestamp.
        static::query()->upsert(
            [[
                'agent_id' => $agentId,
                'question' => $question,
                'question_hash' => $hash,
                'top_score' => $topScore,
                'asked_count' => 1,
                'last_asked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['agent_id', 'question_hash'],
            [
                'asked_count' => DB::raw('asked_count + 1'),
                'top_score' => DB::raw('greatest(top_score, values(top_score))'),
                'last_asked_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
