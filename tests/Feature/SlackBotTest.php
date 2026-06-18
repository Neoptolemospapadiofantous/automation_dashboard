<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Slack\Commands\SlashCommand;
use App\Slack\Commands\SlashContext;
use App\Slack\SlackAgentResponder;
use App\Slack\SlackEventRouter;
use App\Support\Slack\SlackApi;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The local-team Slack bot: event routing + admin allowlist, the LLM bridge's
 * billing, and the production/config guards on the daemon. Web API calls are
 * faked (Http::fake) — no socket, no network.
 */
class SlackBotTest extends TestCase
{
    use RefreshDatabase;

    private function api(): SlackApi
    {
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.app_token' => 'xapp-test']);
        Http::fake(['slack.com/api/*' => Http::response(['ok' => true, 'channel' => ['id' => 'C9', 'name' => 'x']])]);

        return new SlackApi;
    }

    /** A responder that echoes, so router tests don't touch the runtime. */
    private function echoResponder(): SlackAgentResponder
    {
        return new class extends SlackAgentResponder
        {
            public function __construct() {}

            public function reply(string $slackUserId, string $channel, string $text): string
            {
                return "ECHO:{$text}";
            }
        };
    }

    public function test_app_mention_is_answered_in_thread(): void
    {
        $router = new SlackEventRouter($this->api(), $this->echoResponder(), []);

        $handled = $router->handle([
            'type' => 'events_api',
            'payload' => ['event' => [
                'type' => 'app_mention',
                'user' => 'U1', 'channel' => 'C1', 'text' => '<@UBOT> hello', 'ts' => '111.1',
            ]],
        ]);

        $this->assertTrue($handled);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat.postMessage')
            && $r['text'] === 'ECHO:hello' && ($r['thread_ts'] ?? null) === '111.1');
    }

    public function test_bot_own_messages_are_ignored(): void
    {
        $router = new SlackEventRouter($this->api(), $this->echoResponder(), []);

        $handled = $router->handle([
            'type' => 'events_api',
            'payload' => ['event' => ['type' => 'app_mention', 'bot_id' => 'B1', 'text' => 'loop?']],
        ]);

        $this->assertFalse($handled);
        Http::assertNothingSent();
    }

    public function test_admin_only_slash_command_is_refused_for_non_admins(): void
    {
        config(['services.slack.admin_users' => ['U-ADMIN']]);
        $cmd = $this->stubCommand('agent', requiresAdmin: true);
        $router = new SlackEventRouter($this->api(), $this->echoResponder(), [$cmd]);

        $router->handle([
            'type' => 'slash_commands',
            'payload' => ['command' => '/agent', 'text' => 'hi', 'user_id' => 'U-RANDO', 'channel_id' => 'C1'],
        ]);

        $this->assertFalse($cmd->ran);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'restricted'));
    }

    public function test_admin_slash_command_runs_for_allowlisted_user(): void
    {
        config(['services.slack.admin_users' => ['U-ADMIN']]);
        $cmd = $this->stubCommand('agent', requiresAdmin: true);
        $router = new SlackEventRouter($this->api(), $this->echoResponder(), [$cmd]);

        $router->handle([
            'type' => 'slash_commands',
            'payload' => ['command' => '/agent', 'text' => 'go', 'user_id' => 'U-ADMIN', 'channel_id' => 'C1'],
        ]);

        $this->assertTrue($cmd->ran);
        Http::assertSent(fn ($r) => $r['text'] === 'HANDLED');
    }

    public function test_responder_bills_one_user_message_plus_replies(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();
        $start = (int) $team->credit_balance;

        config(['services.slack.team_id' => $team->id]);
        $this->app->instance(Runtime::class, $this->fakeRuntime('hi there'));

        $reply = $this->app->make(SlackAgentResponder::class)->reply('U1', 'C1', 'hello');

        $this->assertSame('hi there', $reply);
        // 1 user message + 1 reply, at 1 credit/message.
        $this->assertSame($start - 2, (int) $team->fresh()->credit_balance);
    }

    public function test_responder_refuses_when_team_is_out_of_credits(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 0, 'topup_balance' => 0])->save();

        config(['services.slack.team_id' => $team->id]);

        $reply = $this->app->make(SlackAgentResponder::class)->reply('U1', 'C1', 'hello');
        $this->assertStringContainsString('out of credits', $reply);
    }

    public function test_responder_warns_when_no_team_configured(): void
    {
        config(['services.slack.team_id' => null]);
        $reply = $this->app->make(SlackAgentResponder::class)->reply('U1', 'C1', 'hi');
        $this->assertStringContainsString('No Slack team is configured', $reply);
    }

    public function test_daemon_refuses_to_run_in_production(): void
    {
        config(['services.slack.bot_token' => 'xoxb', 'services.slack.app_token' => 'xapp']);
        $this->app['env'] = 'production';

        $this->artisan('slack:listen')
            ->expectsOutputToContain('disabled in production')
            ->assertFailed();
    }

    public function test_daemon_refuses_without_tokens(): void
    {
        config(['services.slack.bot_token' => null, 'services.slack.app_token' => null]);

        $this->artisan('slack:listen')
            ->expectsOutputToContain('must both be set')
            ->assertFailed();
    }

    /** A no-op slash command that records whether it ran. */
    private function stubCommand(string $name, bool $requiresAdmin): SlashCommand
    {
        return new class($name, $requiresAdmin) implements SlashCommand
        {
            public bool $ran = false;

            public function __construct(private string $n, private bool $admin) {}

            public function name(): string
            {
                return $this->n;
            }

            public function requiresAdmin(): bool
            {
                return $this->admin;
            }

            public function handle(SlashContext $ctx): string
            {
                $this->ran = true;

                return 'HANDLED';
            }
        };
    }

    /** A Runtime whose sendText returns a single text trace. */
    private function fakeRuntime(string $message): Runtime
    {
        return new class($message) implements Runtime
        {
            public function __construct(private string $message) {}

            public function launch(Agent $agent, string $visitorId): array
            {
                return [];
            }

            public function hasSession(Agent $agent, string $visitorId): bool
            {
                return false;
            }

            public function transcript(Agent $agent, string $visitorId): array
            {
                return [];
            }

            public function sendText(Agent $agent, string $visitorId, string $text): array
            {
                return [['type' => 'text', 'payload' => ['message' => $this->message]]];
            }

            public function streamText(Agent $agent, string $visitorId, string $text): Generator
            {
                yield from [];
            }

            public function endSession(Agent $agent, string $visitorId): void {}

            public function health(Agent $agent): array
            {
                return ['ok' => true];
            }
        };
    }
}
