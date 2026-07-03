<?php

namespace App\Runtime\Canned;

use App\Models\AgentConfigVersion;
use Illuminate\Support\Str;

/**
 * The deterministic FAQ shortcuts an agent exposes, read from its PUBLISHED
 * config (`config['canned_answers']`) — same draft→publish→rollback lifecycle
 * as automations, and drafts are invisible to the runtime for the same reason.
 *
 * Two jobs: hand the widget its quick-reply chips (chips()), and resolve an
 * incoming visitor message to a stored answer BEFORE the LLM is ever asked
 * (match()). A hit means the turn costs nothing — no provider call, no credit.
 */
class CannedAnswers
{
    /** @var list<CannedAnswer>|null */
    private ?array $answers = null;

    public function __construct(private readonly int $agentId) {}

    public static function forAgent(int $agentId): self
    {
        return new self($agentId);
    }

    /** @return list<CannedAnswer> */
    public function all(): array
    {
        if ($this->answers !== null) {
            return $this->answers;
        }

        $config = AgentConfigVersion::publishedConfig($this->agentId) ?? [];
        $raw = $config['canned_answers'] ?? [];

        $answers = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $answer = CannedAnswer::fromConfig($row);
            // First definition wins on a duplicate category — a later dupe
            // can't shadow an earlier chip.
            if ($answer === null || isset($seen[Str::lower($answer->category)])) {
                continue;
            }
            $seen[Str::lower($answer->category)] = true;
            $answers[] = $answer;
        }

        return $this->answers = $answers;
    }

    /**
     * The category labels, in order — the widget renders these as quick-reply
     * chips a visitor can tap instead of typing.
     *
     * @return list<string>
     */
    public function chips(): array
    {
        return array_map(fn (CannedAnswer $a): string => $a->category, $this->all());
    }

    /**
     * Resolve a visitor message to its canned answer, or null if none applies
     * (the turn falls through to the LLM). Blank messages never match.
     */
    public function match(string $message): ?CannedAnswer
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return null;
        }

        foreach ($this->all() as $answer) {
            if ($answer->matches($lower)) {
                return $answer;
            }
        }

        return null;
    }
}
