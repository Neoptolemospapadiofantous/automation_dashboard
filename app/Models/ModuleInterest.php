<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team asking for a suite module that is not built yet. Unique per
 * team and module, so a second click is a no-op rather than a second vote.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $module_key
 */
class ModuleInterest extends Model
{
    protected $fillable = ['team_id', 'user_id', 'module_key'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
