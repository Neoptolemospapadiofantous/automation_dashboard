<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Tiny key/value model for editable platform-wide settings.
 *
 * Read via PlatformSetting::value($key, $default). Writes go through
 * PlatformSetting::put($key, $value) so we touch updated_at + bust the
 * cached public stats response in one place.
 *
 * Don't add domain logic here — for anything non-trivial, model it
 * properly. This is for hand-managed marketing copy and capacity numbers.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    public static function value(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row?->value ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = static::value($key);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_null($value) ? null : (string) $value, 'updated_at' => now()],
        );

        // Bust the cached public stats response — operators set values via
        // CLI expecting the landing site to pick them up on next page load,
        // not 5 minutes later when the cache TTL expires.
        Cache::forget('public_stats');
    }
}
