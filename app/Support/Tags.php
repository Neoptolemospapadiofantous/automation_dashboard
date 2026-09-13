<?php

namespace App\Support;

/**
 * Normaliser for the free-form tag/label lists on leads and conversations:
 * lower-cased, trimmed, deduped, capped in count and length, and never
 * containing an empty string. One place, so a tag typed on the board and a
 * label typed on a transcript agree on what "the same tag" means.
 */
final class Tags
{
    public const MAX_TAGS = 20;

    public const MAX_LENGTH = 40;

    /**
     * @param  mixed  $raw  anything a form or a CSV cell might hand over
     * @return list<string>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,;|]/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }
            $tag = mb_strtolower(trim((string) $tag));
            $tag = preg_replace('/\s+/', ' ', $tag) ?? $tag;
            if ($tag === '' || mb_strlen($tag) > self::MAX_LENGTH) {
                continue;
            }
            $out[$tag] = true;
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return array_keys($out);
    }
}
