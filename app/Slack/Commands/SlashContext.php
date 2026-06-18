<?php

namespace App\Slack\Commands;

/**
 * Immutable invocation context for a slash command, parsed from the Socket Mode
 * `slash_commands` envelope payload.
 */
class SlashContext
{
    public function __construct(
        public readonly string $text,      // everything after the command
        public readonly string $userId,    // invoking Slack user
        public readonly string $channelId,
    ) {}

    /** First whitespace-delimited token of the argument text (lowercased). */
    public function verb(): string
    {
        return strtolower(strtok(trim($this->text), " \t") ?: '');
    }

    /** Argument text with the leading verb removed. */
    public function rest(): string
    {
        $trimmed = trim($this->text);
        $verb = $this->verb();

        return $verb === '' ? '' : trim(substr($trimmed, strlen($verb)));
    }
}
