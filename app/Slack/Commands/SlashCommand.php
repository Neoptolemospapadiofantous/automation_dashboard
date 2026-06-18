<?php

namespace App\Slack\Commands;

/**
 * A slash-command handler. The router resolves one by its `name()` (the part
 * after the leading slash) and calls `handle()` with the invocation context.
 *
 * `requiresAdmin()` gates the command behind the SLACK_ADMIN_USERS allowlist —
 * the router refuses to dispatch it for non-admins, so handlers never need to
 * re-check authorization.
 */
interface SlashCommand
{
    /** The command keyword without the leading slash, e.g. "hermes-status". */
    public function name(): string;

    /** True if only allowlisted admins may invoke it (spend credits / admin Slack). */
    public function requiresAdmin(): bool;

    /**
     * Handle an invocation and return the reply text posted back to Slack.
     */
    public function handle(SlashContext $ctx): string;
}
