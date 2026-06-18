<?php

namespace Tests\Feature;

use App\Support\Slack\SlackWebhook;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fully-local Slack agent feed: a SlackWebhook poster (Incoming Webhook,
 * no bot token) driving hermes:alert and slack:digest. Verifies the no-config
 * silence contract, payload shape, and failure handling — without ever hitting
 * the network (Http::fake).
 */
class SlackAgentFeedTest extends TestCase
{
    private const HOOK = 'https://hooks.slack.com/services/T000/B000/xxx';

    public function test_webhook_is_disabled_and_silent_when_url_is_unset(): void
    {
        Http::fake();
        config(['services.slack.webhook_url' => null]);

        $slack = new SlackWebhook;

        $this->assertFalse($slack->enabled());
        $this->assertFalse($slack->send('hi'));
        Http::assertNothingSent();
    }

    public function test_send_posts_text_and_blocks_to_the_configured_webhook(): void
    {
        Http::fake([self::HOOK => Http::response('ok', 200)]);
        config(['services.slack.webhook_url' => self::HOOK]);

        $slack = new SlackWebhook;
        $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'body']]];

        $this->assertTrue($slack->enabled());
        $this->assertTrue($slack->send('fallback', $blocks));

        Http::assertSent(function ($request) use ($blocks) {
            return $request->url() === self::HOOK
                && $request['text'] === 'fallback'
                && $request['blocks'] === $blocks;
        });
    }

    public function test_send_returns_false_when_slack_rejects(): void
    {
        Http::fake([self::HOOK => Http::response('invalid_payload', 400)]);
        config(['services.slack.webhook_url' => self::HOOK]);

        $this->assertFalse((new SlackWebhook)->send('boom'));
    }

    public function test_hermes_alert_is_silent_without_a_webhook(): void
    {
        Http::fake();
        config(['services.slack.webhook_url' => null]);

        $this->artisan('hermes:alert')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_slack_digest_posts_a_snapshot_when_configured(): void
    {
        Http::fake([self::HOOK => Http::response('ok', 200)]);
        config(['services.slack.webhook_url' => self::HOOK]);

        // Reads the real data/agents/*/findings.json reports checked into the
        // repo. As long as at least one collector report exists, it posts.
        $this->artisan('slack:digest')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::HOOK
            && str_contains((string) $request['text'], 'Hermes daily digest'));
    }
}
