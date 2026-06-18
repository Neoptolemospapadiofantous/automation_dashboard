<?php

namespace App\Console\Commands;

use App\Slack\SlackEventRouter;
use App\Support\Slack\SlackApi;
use App\Support\Slack\SocketModeClient;
use Illuminate\Console\Command;
use React\EventLoop\Loop;

/**
 * The local-team Slack bot daemon. Opens a Socket Mode WebSocket (fully local —
 * no public inbound endpoint) and lets the agent manage Slack: reply to
 *
 * @mentions/DMs via the LLM runtime, run slash commands, and administer channels.
 *
 * LOCAL ONLY BY DESIGN: this is an internal local-team tool, so it hard-refuses
 * to run in production. The prod-safe one-way alert/digest lane (SlackWebhook)
 * is separate and unaffected. Run it locally with `php artisan slack:listen`;
 * see docs/operations/slack-bot.md. Not scheduled and not a prod daemon.
 */
class SlackListenCommand extends Command
{
    protected $signature = 'slack:listen';

    protected $description = 'Run the local-team Slack bot (Socket Mode). Disabled in production.';

    public function handle(SlackApi $api, SocketModeClient $socket, SlackEventRouter $router): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('slack:listen is disabled in production by design — this is a local-team bot only.');

            return self::FAILURE;
        }

        if (! $api->configured()) {
            $this->error('SLACK_BOT_TOKEN and SLACK_APP_TOKEN must both be set (see docs/operations/slack-bot.md).');

            return self::FAILURE;
        }

        $loop = Loop::get();

        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM] as $signal) {
                $loop->addSignal($signal, function () use ($loop): void {
                    $this->info('Signal received — shutting down Slack listener.');
                    $loop->stop();
                });
            }
        }

        $this->info('Slack listener starting (Socket Mode, fully local). Ctrl-C to stop.');
        $socket->run($loop, fn (array $envelope) => $router->handle($envelope));
        $loop->run();

        $this->info('Slack listener stopped.');

        return self::SUCCESS;
    }
}
