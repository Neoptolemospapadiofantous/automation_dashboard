<?php

namespace App\Slack;

use App\Slack\Commands\SlashCommand;
use App\Slack\Commands\SlashContext;
use App\Support\Slack\SlackApi;
use Illuminate\Support\Facades\Log;

/**
 * Turns a decoded Socket Mode envelope into an action: routes @mentions and DMs
 * to the LLM responder, and slash commands to their handler. Posts replies via
 * the Web API. Pure dispatch — the transport (SocketModeClient) only hands it
 * decoded payloads, so this is unit-testable without a socket.
 *
 * Authorization lives here: admin-only commands are refused for users not on
 * the SLACK_ADMIN_USERS allowlist, so no handler runs unauthorized.
 */
class SlackEventRouter
{
    /** @var array<string,SlashCommand> keyed by command name */
    private array $commands = [];

    /**
     * @param  iterable<SlashCommand>  $commands
     */
    public function __construct(
        private readonly SlackApi $slack,
        private readonly SlackAgentResponder $responder,
        iterable $commands = [],
    ) {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /**
     * Handle one decoded envelope. Returns true when it was recognised and
     * dispatched (the caller has already acked it).
     *
     * @param  array<string,mixed>  $envelope
     */
    public function handle(array $envelope): bool
    {
        return match ($envelope['type'] ?? '') {
            'events_api' => $this->onEvent((array) ($envelope['payload']['event'] ?? [])),
            'slash_commands' => $this->onSlash((array) ($envelope['payload'] ?? [])),
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function onEvent(array $event): bool
    {
        $type = (string) ($event['type'] ?? '');

        // Ignore the bot's own messages and non-user events to avoid loops.
        if (($event['bot_id'] ?? null) !== null || isset($event['bot_profile'])) {
            return false;
        }

        $isMention = $type === 'app_mention';
        $isDm = $type === 'message' && ($event['channel_type'] ?? '') === 'im';
        if (! $isMention && ! $isDm) {
            return false;
        }

        $user = (string) ($event['user'] ?? '');
        $channel = (string) ($event['channel'] ?? '');
        $text = $this->stripMention((string) ($event['text'] ?? ''));
        if ($user === '' || $channel === '' || $text === '') {
            return false;
        }

        // Acknowledge a mention with a reaction on the triggering message.
        $ts = (string) ($event['ts'] ?? '');
        if ($isMention && $ts !== '') {
            $this->slack->addReaction($channel, $ts, 'eyes');
        }

        $reply = $this->responder->reply($user, $channel, $text);
        // Thread mentions under the triggering message; DMs reply at top level.
        $threadTs = (string) ($event['thread_ts'] ?? $ts) ?: null;
        $this->slack->postMessage($channel, $reply, $isMention ? $threadTs : null);

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function onSlash(array $payload): bool
    {
        $name = ltrim((string) ($payload['command'] ?? ''), '/');
        $command = $this->commands[$name] ?? null;
        $channel = (string) ($payload['channel_id'] ?? '');
        $user = (string) ($payload['user_id'] ?? '');

        if ($command === null) {
            Log::info("SlackEventRouter: unknown slash command /{$name}");

            return false;
        }

        $isAdmin = $this->isAdmin($user);
        if ($command->requiresAdmin() && ! $isAdmin) {
            $this->slack->postMessage($channel, ":no_entry: `/{$name}` is restricted to approved operators.");

            return true;
        }

        $ctx = new SlashContext(
            text: (string) ($payload['text'] ?? ''),
            userId: $user,
            channelId: $channel,
        );

        $this->slack->postMessage($channel, $command->handle($ctx));

        return true;
    }

    private function isAdmin(string $userId): bool
    {
        return $userId !== '' && in_array($userId, (array) config('services.slack.admin_users', []), true);
    }

    /** Drop the leading <@U…> bot mention from an app_mention text. */
    private function stripMention(string $text): string
    {
        return trim(preg_replace('/<@[A-Z0-9]+>/', '', $text) ?? $text);
    }
}
