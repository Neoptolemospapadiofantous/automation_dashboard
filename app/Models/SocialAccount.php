<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google or Microsoft identity linked to a user (see SocialAuthController).
 *
 * @property int $user_id
 * @property string $provider
 * @property string $provider_id
 */
class SocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_id', 'email', 'name', 'avatar'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
