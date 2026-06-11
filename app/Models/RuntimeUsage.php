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
 * @property int $tokens_out
 * @property int $tokens_in_enhanced
 * @property int $tokens_out_enhanced
 * @property int $tokens_in_opus
 * @property int $tokens_out_opus
 */
class RuntimeUsage extends Model
{
    protected $table = 'runtime_usage';

    protected $fillable = [
        'team_id',
        'agent_id',
        'date',
        'turns',
        'tokens_in',
        'tokens_out',
        'tokens_in_enhanced',
        'tokens_out_enhanced',
        'tokens_in_opus',
        'tokens_out_opus',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Increment today's rollup for an agent's team. Tokens land in the
     * bucket for the tier that served the turn (tiers price differently,
     * so the margin report needs them separated). 2-3 cheap queries —
     * negligible next to the multi-second LLM call in the same turn.
     */
    public static function record(Agent $agent, int $tokensIn, int $tokensOut, string $tier = 'haiku'): void
    {
        // startOfDay() Carbon on BOTH lookup and insert so the value passes
        // through the date cast identically — a raw string here fails to
        // match the cast-serialized stored value, and the resulting create
        // would trip the unique constraint instead of incrementing.
        $row = static::query()->firstOrCreate([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'date' => now()->startOfDay(),
        ]);

        // Column lineage: tokens_in/out = haiku (née standard),
        // *_enhanced = sonnet, *_opus = opus.
        [$inCol, $outCol] = match ($tier) {
            'sonnet', 'enhanced' => ['tokens_in_enhanced', 'tokens_out_enhanced'],
            'opus' => ['tokens_in_opus', 'tokens_out_opus'],
            default => ['tokens_in', 'tokens_out'],
        };

        $row->increment('turns');
        if ($tokensIn > 0) {
            $row->increment($inCol, $tokensIn);
        }
        if ($tokensOut > 0) {
            $row->increment($outCol, $tokensOut);
        }
    }
}
