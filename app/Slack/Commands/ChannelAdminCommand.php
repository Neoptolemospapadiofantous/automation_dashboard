<?php

namespace App\Slack\Commands;

use App\Support\Slack\SlackApi;

/**
 * `/channel <create|archive|topic> …` — channel administration via the Web API.
 * Admin-only (enforced by the router). Subcommands:
 *   /channel create <name> [private]
 *   /channel archive <#channel-id>
 *   /channel topic <#channel-id> <topic text>
 */
class ChannelAdminCommand implements SlashCommand
{
    public function __construct(
        private readonly SlackApi $slack,
    ) {}

    public function name(): string
    {
        return 'channel';
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function handle(SlashContext $ctx): string
    {
        return match ($ctx->verb()) {
            'create' => $this->create($ctx->rest()),
            'archive' => $this->archive($ctx->rest()),
            'topic' => $this->topic($ctx->rest()),
            'invite' => $this->invite($ctx->rest()),
            default => 'Usage: `/channel create <name> [private]` · `/channel archive <id>` · `/channel topic <id> <text>` · `/channel invite <id> <@user…>`',
        };
    }

    private function create(string $args): string
    {
        $parts = preg_split('/\s+/', trim($args)) ?: [];
        $name = $parts[0] ?? '';
        if ($name === '') {
            return 'Usage: `/channel create <name> [private]`';
        }
        $private = strtolower($parts[1] ?? '') === 'private';

        $res = $this->slack->createChannel($name, $private);

        return ($res['ok'] ?? false)
            ? ":white_check_mark: Created <#{$res['channel']['id']}|{$res['channel']['name']}>."
            : ':x: Could not create channel: '.($res['error'] ?? 'unknown_error');
    }

    private function archive(string $args): string
    {
        $channel = $this->channelId($args);
        if ($channel === '') {
            return 'Usage: `/channel archive <#channel>`';
        }

        $res = $this->slack->archiveChannel($channel);

        return ($res['ok'] ?? false)
            ? ':white_check_mark: Archived the channel.'
            : ':x: Could not archive: '.($res['error'] ?? 'unknown_error');
    }

    private function topic(string $args): string
    {
        $channel = $this->channelId(strtok($args, " \t") ?: '');
        $topic = trim(substr(trim($args), strlen(strtok(trim($args), " \t") ?: '')));
        if ($channel === '' || $topic === '') {
            return 'Usage: `/channel topic <#channel> <topic text>`';
        }

        $res = $this->slack->setTopic($channel, $topic);

        return ($res['ok'] ?? false)
            ? ':white_check_mark: Topic updated.'
            : ':x: Could not set topic: '.($res['error'] ?? 'unknown_error');
    }

    private function invite(string $args): string
    {
        $channel = $this->channelId(strtok(trim($args), " \t") ?: '');
        preg_match_all('/<@([A-Z0-9]+)(\|[^>]*)?>/', $args, $m);
        $users = $m[1];

        if ($channel === '' || $users === []) {
            return 'Usage: `/channel invite <#channel> <@user…>`';
        }

        $res = $this->slack->inviteUsers($channel, $users);

        return ($res['ok'] ?? false)
            ? ':white_check_mark: Invited '.count($users).' user(s).'
            : ':x: Could not invite: '.($res['error'] ?? 'unknown_error');
    }

    /** Accept either a raw channel id (C…) or a Slack <#C…|name> mention. */
    private function channelId(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/<#([A-Z0-9]+)(\|[^>]*)?>/', $raw, $m)) {
            return $m[1];
        }

        return ltrim($raw, '#');
    }
}
