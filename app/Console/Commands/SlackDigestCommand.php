<?php

namespace App\Console\Commands;

use App\Support\Slack\SlackWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Posts a daily roll-up of all Hermes collector activity to Slack — the
 * "keep writing to Slack" heartbeat. Unlike hermes:alert (which fires only on
 * CRITICAL/FAIL changes), this posts every run regardless of status, so the
 * channel always shows the latest snapshot: audit posture, system health,
 * outdated-dependency counts, and when the fleet last ran.
 *
 * Fully local: a single Incoming-Webhook POST via {@see SlackWebhook}; all data
 * is read from data/agents/<collector>/findings.json on disk. No bot token,
 * no Web API. Scheduled daily in routes/console.php; runnable by hand
 * (`php artisan slack:digest`).
 */
class SlackDigestCommand extends Command
{
    protected $signature = 'slack:digest';

    protected $description = 'Post a daily Hermes activity digest to Slack.';

    public function handle(SlackWebhook $slack): int
    {
        if (! $slack->enabled()) {
            Log::warning('slack:digest — SLACK_ALERT_WEBHOOK_URL is unset; nothing posted.');
            $this->warn('SLACK_ALERT_WEBHOOK_URL is unset — logged, not posted.');

            return self::SUCCESS;
        }

        $lines = array_filter([
            $this->auditLine(),
            $this->systemLine(),
            $this->updateLine(),
            $this->fleetLine(),
        ]);

        if ($lines === []) {
            $this->info('No collector reports found — nothing to digest.');

            return self::SUCCESS;
        }

        $env = (string) config('app.env');
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "📊 Hermes daily digest — {$env}"],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)],
            ],
            [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => 'Snapshot of the latest collector reports. `composer hermes-status` for detail.',
                ]],
            ],
        ];

        if (! $slack->send("📊 Hermes daily digest — {$env}", $blocks)) {
            $this->error('Slack post failed — see logs.');

            return self::FAILURE;
        }

        $this->info('Posted Hermes digest to Slack.');

        return self::SUCCESS;
    }

    private function auditLine(): ?string
    {
        $d = $this->report('audit-sentinel');
        if ($d === null) {
            return null;
        }

        $counts = sprintf(
            '%d critical · %d high · %d medium · %d low',
            (int) ($d['critical'] ?? 0), (int) ($d['high'] ?? 0),
            (int) ($d['medium'] ?? 0), (int) ($d['low'] ?? 0),
        );

        return $this->emoji((string) ($d['overall'] ?? '?'))
            .' *Audit Sentinel:* '.($d['overall'] ?? '?')." ({$counts})".$this->age($d);
    }

    private function systemLine(): ?string
    {
        $d = $this->report('system-check');
        if ($d === null) {
            return null;
        }

        $counts = sprintf('%d pass · %d warn · %d fail',
            (int) ($d['pass'] ?? 0), (int) ($d['warn'] ?? 0), (int) ($d['fail'] ?? 0));

        return $this->emoji((string) ($d['overall'] ?? '?'))
            .' *System Check:* '.($d['overall'] ?? '?')." ({$counts})".$this->age($d);
    }

    private function updateLine(): ?string
    {
        $d = $this->report('update-inspector');
        if ($d === null) {
            return null;
        }

        $counts = sprintf('PHP %d outdated (%d major) · JS %d outdated (%d major)',
            (int) ($d['php_total'] ?? 0), (int) ($d['php_major'] ?? 0),
            (int) ($d['js_total'] ?? 0), (int) ($d['js_major'] ?? 0));

        return $this->emoji((string) ($d['overall'] ?? '?'))
            .' *Update Inspector:* '.($d['overall'] ?? '?')." ({$counts})".$this->age($d);
    }

    private function fleetLine(): ?string
    {
        $base = base_path('data/agents/fleet');
        if (! is_dir($base)) {
            return null;
        }

        $runs = array_filter((array) scandir($base) ?: [], static fn ($e) => is_string($e) && $e[0] !== '.');
        if ($runs === []) {
            return null;
        }

        sort($runs);
        $latest = (string) end($runs);

        return "🤖 *Fleet:* last run `{$latest}`";
    }

    /**
     * Decode a collector report, or null when absent/unreadable.
     *
     * @return array<string,mixed>|null
     */
    private function report(string $dir): ?array
    {
        $path = base_path("data/agents/{$dir}/findings.json");
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * A " — <ts>" suffix when the report carries a timestamp.
     *
     * @param  array<string,mixed>  $d
     */
    private function age(array $d): string
    {
        $ts = (string) ($d['ts'] ?? '');

        return $ts !== '' ? " — {$ts}" : '';
    }

    private function emoji(string $overall): string
    {
        return match (strtoupper($overall)) {
            'PASS', 'OK' => '✅',
            'WARN' => '⚠️',
            'FAIL', 'CRITICAL', 'ERROR' => '🚨',
            default => '•',
        };
    }
}
