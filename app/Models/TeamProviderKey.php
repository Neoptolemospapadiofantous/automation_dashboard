<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer-supplied provider API key (bring-your-own-key).
 *
 * Chat turns that run on one of these cost the team 0 credits and count
 * against Plan::monthlyMessageCap() instead — see App\Billing\OwnKey for
 * the resolution rules and the cap.
 *
 * SECURITY: `api_key` is an encrypted cast, so it is ciphertext at rest and
 * decrypted only when a completion is about to be made. It is never
 * serialized ($hidden), never logged, and never included in a BI payload.
 * `key_hint` is the only representation that reaches a browser.
 *
 * @property string $provider
 * @property string $api_key
 * @property string|null $key_hint
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error
 */
class TeamProviderKey extends Model
{
    protected $fillable = [
        'team_id',
        'provider',
        'api_key',
        'key_hint',
        'last_verified_at',
        'last_error',
    ];

    /**
     * The key must never leave the server. Hiding it here means an
     * accidental ->toArray() or a JSON response can't leak it.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Last four characters, for display. Derived on write so the UI never
     * needs to decrypt.
     */
    public static function hintFor(string $key): string
    {
        return '…'.substr(trim($key), -4);
    }

    /**
     * Usable = stored AND last verification succeeded. A key whose probe
     * failed stays on the row (so the user can see the error and fix it)
     * but must not be handed to a visitor's turn.
     */
    public function isUsable(): bool
    {
        return $this->last_verified_at !== null && ($this->last_error ?? '') === '';
    }
}
