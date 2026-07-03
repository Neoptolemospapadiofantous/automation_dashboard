<?php

namespace App\Runtime\Canned;

use Illuminate\Support\Str;

/**
 * One deterministic FAQ shortcut: a category label the widget shows as a
 * quick-reply chip, the keywords that route a typed question to it, and the
 * exact answer served WITHOUT calling the LLM (zero tokens, zero credits).
 *
 * This is the cheapest possible turn — for a landing page, the handful of
 * "pricing / features / how it works" questions are most of the traffic, and
 * a canned answer skips the model entirely.
 */
final class CannedAnswer
{
    /**
     * @param  list<string>  $keywords  Lowercased substrings that route a typed message here.
     */
    public function __construct(
        public readonly string $category,
        public readonly array $keywords,
        public readonly string $answer,
    ) {}

    /**
     * Build from a stored config row, or null if it's malformed (missing a
     * category or answer). Mirrors AutomationAction::fromConfig — one bad row
     * never breaks the others or the turn.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromConfig(array $row): ?self
    {
        $category = trim((string) ($row['category'] ?? ''));
        $answer = trim((string) ($row['answer'] ?? ''));
        if ($category === '' || $answer === '') {
            return null;
        }

        $keywords = [];
        foreach ((array) ($row['keywords'] ?? []) as $kw) {
            $kw = Str::lower(trim((string) $kw));
            if ($kw !== '') {
                $keywords[] = $kw;
            }
        }

        return new self($category, array_values(array_unique($keywords)), $answer);
    }

    /**
     * Does this shortcut answer the given (already lowercased) message? A chip
     * click sends the category label verbatim, so an exact category match wins
     * first; otherwise any keyword appearing in the message routes here.
     */
    public function matches(string $lowerMessage): bool
    {
        if ($lowerMessage === Str::lower($this->category)) {
            return true;
        }

        foreach ($this->keywords as $kw) {
            if (str_contains($lowerMessage, $kw)) {
                return true;
            }
        }

        return false;
    }
}
