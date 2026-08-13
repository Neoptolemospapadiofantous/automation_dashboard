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
     * @param  list<string>  $keywords  Lowercased whole words/phrases that route a typed message here.
     * @param  bool  $escalate  Serving this answer also flags the conversation
     *                          for human handoff and notifies the owner — for
     *                          "talk to a human"-type chips, where the canned
     *                          reply must not swallow the escalation signal.
     */
    public function __construct(
        public readonly string $category,
        public readonly array $keywords,
        public readonly string $answer,
        public readonly bool $escalate = false,
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

        return new self($category, array_values(array_unique($keywords)), $answer, (bool) ($row['escalate'] ?? false));
    }

    /**
     * Does this shortcut answer the given (already lowercased) message? A chip
     * click sends the category label verbatim, so an exact category match wins
     * first; otherwise any keyword appearing as a whole word/phrase routes
     * here. Whole-word, not substring: "try" must not fire on "industry",
     * "custom" on "customer", or "api" on "rapid" — a canned answer served
     * for the wrong question reads as the agent ignoring the visitor.
     */
    public function matches(string $lowerMessage): bool
    {
        if ($lowerMessage === Str::lower($this->category)) {
            return true;
        }

        foreach ($this->keywords as $kw) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($kw, '/').'(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $lowerMessage) === 1) {
                return true;
            }
        }

        return false;
    }
}
