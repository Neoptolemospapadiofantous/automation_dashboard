<?php

namespace App\Support\Slack;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack Web API client (bot token, xoxb-) — the outbound action surface for the
 * two-way bot: post/thread, reactions, and channel administration, plus
 * apps.connections.open which mints the Socket Mode WebSocket URL.
 *
 * Local-team tool only: the bot daemon (slack:listen) refuses to run in
 * production, so this client is never exercised there. Uses the Http facade
 * with the repo's timeout/retry convention; every call returns the parsed
 * Slack response array ({ok: bool, ...}). Slack signals failure with
 * HTTP 200 + {"ok": false, "error": "..."}, so we inspect the body, not status.
 */
class SlackApi
{
    private const BASE = 'https://slack.com/api';

    public function __construct(
        private readonly ?string $botToken = null,
        private readonly ?string $appToken = null,
    ) {}

    public function configured(): bool
    {
        return $this->bot() !== '' && $this->app() !== '';
    }

    /**
     * Open a Socket Mode connection and return its single-use wss:// URL.
     * Uses the APP-level token (not the bot token). Returns '' on failure.
     */
    public function openConnection(): string
    {
        $res = $this->post('apps.connections.open', [], $this->app());

        return (string) ($res['url'] ?? '');
    }

    /**
     * Post a message. $threadTs threads the reply; $blocks is optional Block Kit.
     *
     * @param  list<array<string,mixed>>  $blocks
     * @return array<string,mixed>
     */
    public function postMessage(string $channel, string $text, ?string $threadTs = null, array $blocks = []): array
    {
        $payload = array_filter([
            'channel' => $channel,
            'text' => $text,
            'thread_ts' => $threadTs,
            'blocks' => $blocks !== [] ? $blocks : null,
        ], static fn ($v) => $v !== null);

        return $this->post('chat.postMessage', $payload);
    }

    /** @return array<string,mixed> */
    public function addReaction(string $channel, string $timestamp, string $emoji): array
    {
        return $this->post('reactions.add', [
            'channel' => $channel,
            'timestamp' => $timestamp,
            'name' => trim($emoji, ': '),
        ]);
    }

    /** @return array<string,mixed> */
    public function createChannel(string $name, bool $private = false): array
    {
        return $this->post('conversations.create', [
            'name' => $name,
            'is_private' => $private,
        ]);
    }

    /** @return array<string,mixed> */
    public function archiveChannel(string $channel): array
    {
        return $this->post('conversations.archive', ['channel' => $channel]);
    }

    /** @return array<string,mixed> */
    public function setTopic(string $channel, string $topic): array
    {
        return $this->post('conversations.setTopic', [
            'channel' => $channel,
            'topic' => $topic,
        ]);
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string,mixed>
     */
    public function inviteUsers(string $channel, array $userIds): array
    {
        return $this->post('conversations.invite', [
            'channel' => $channel,
            'users' => implode(',', $userIds),
        ]);
    }

    /**
     * POST a Web API method. Returns the decoded body; on transport failure
     * returns ['ok' => false, 'error' => '...'] so callers never see an
     * exception. $token defaults to the bot token.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $method, array $payload, ?string $token = null): array
    {
        $token ??= $this->bot();
        if ($token === '') {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        try {
            /** @var Response $res */
            $res = Http::baseUrl(self::BASE)
                ->withToken($token)
                ->asJson()
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->post('/'.$method, $payload);
        } catch (\Throwable $e) {
            Log::warning("SlackApi: {$method} threw — ".$e->getMessage());

            return ['ok' => false, 'error' => 'transport_exception'];
        }

        $body = (array) $res->json();
        if (($body['ok'] ?? false) !== true) {
            Log::warning("SlackApi: {$method} returned not-ok — ".($body['error'] ?? $res->body()));
        }

        return $body;
    }

    private function bot(): string
    {
        return trim((string) ($this->botToken ?? config('services.slack.bot_token') ?? ''));
    }

    private function app(): string
    {
        return trim((string) ($this->appToken ?? config('services.slack.app_token') ?? ''));
    }
}
