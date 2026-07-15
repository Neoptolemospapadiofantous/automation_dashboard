<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-day token usage rollup (team × agent × date). Written by the
 * FlowExecutor after every turn; read by `runtime:costs` for the
 * platform margin view. See the migration for why this exists alongside
 * the per-session token counters.
 *
 * @property int $team_id
 * @property int|null $agent_id
 * @property Carbon $date
 * @property int $turns
 * @property int $tokens_in
 * @property string $tier
 * @property int $tokens_out
 * @property int $canned_turns
 * @property int $kb_hits
 * @property int $low_confidence_turns
 */
class RuntimeUsage extends Model
{
    protected $table = 'runtime_usage';

    protected $fillable = [
        'team_id',
        'agent_id',
        'date',
        'tier',
        'turns',
        'tokens_in',
        'tokens_out',
        'canned_turns',
        'kb_hits',
        'low_confidence_turns',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Increment today's rollup for an agent's team. One row per
     * (team, agent, date, tier) — tiers price differently, so the margin
     * report needs them separated. 2-3 cheap queries — negligible next
     * to the multi-second LLM call in the same turn.
     */
    public static function record(Agent $agent, int $tokensIn, int $tokensOut, string $tier = 'haiku', bool $kbHit = false, bool $lowConfidence = false): void
    {
        $row = static::today($agent, $tier);

        $row->increment('turns');
        if ($tokensIn > 0) {
            $row->increment('tokens_in', $tokensIn);
        }
        if ($tokensOut > 0) {
            $row->increment('tokens_out', $tokensOut);
        }
        if ($kbHit) {
            $row->increment('kb_hits');
        }
        if ($lowConfidence) {
            $row->increment('low_confidence_turns');
        }
    }

    /**
     * A canned-answer turn: deflected before the LLM, so no tokens and no
     * turn increment — counted separately for the deflection-rate tile.
     */
    public static function recordCanned(Agent $agent, string $tier = 'haiku'): void
    {
        static::today($agent, $tier)->increment('canned_turns');
    }

    protected static function today(Agent $agent, string $tier): self
    {
        // startOfDay() Carbon on BOTH lookup and insert so the value passes
        // through the date cast identically — a raw string here fails to
        // match the cast-serialized stored value, and the resulting create
        // would trip the unique constraint instead of incrementing.
        return static::query()->firstOrCreate([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'date' => now()->startOfDay(),
            'tier' => $tier,
        ]);
    }
}
