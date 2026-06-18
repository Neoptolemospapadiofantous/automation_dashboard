<?php

namespace App\Console\Commands;

use App\Support\Slack\SlackWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reads the latest Hermes collector reports and posts to Slack when there are
 * CRITICAL (audit-sentinel) or FAIL (system-check) findings.
 *
 * Delivery is a fully-local Slack Incoming-Webhook POST (see {@see SlackWebhook})
 * — no bot token, no Web API. Dedupes via storage/app/hermes-alert-state.json:
 * only posts when the set of active CRITICAL/FAIL findings changes, so a standing
 * condition doesn't ping the channel on every run. When the set clears, state
 * resets so a later recurrence alerts again. Wired via ->then() right after the
 * collectors in routes/console.php; also runnable by hand
 * (`php artisan hermes:alert [--force]`) for validation.
 */
class HermesAlertCommand extends Command
{
    protected $signature = 'hermes:alert {--force : Send even if the finding-set is unchanged}';

    protected $description = 'Post Hermes CRITICAL/FAIL findings to Slack (deduped).';

    public function handle(SlackWebhook $slack): int
    {
        $findings = array_merge(
            $this->collect('audit-sentinel', 'findings', 'severity', 'CRITICAL'),
            $this->collect('system-check', 'checks', 'status', 'FAIL'),
        );

        $statePath = storage_path('app/hermes-alert-state.json');
        $previous = is_file($statePath) ? trim((string) file_get_contents($statePath)) : '';

        if ($findings === []) {
            // Cleared — reset state so a recurrence re-alerts.
            @file_put_contents($statePath, '');
            $this->info('No CRITICAL/FAIL findings.');

            return self::SUCCESS;
        }

        $signature = $this->signature($findings);

        if (! $this->option('force') && $signature === $previous) {
            $this->info(count($findings).' finding(s), unchanged since last alert — not re-sending.');

            return self::SUCCESS;
        }

        if (! $slack->enabled()) {
            Log::warning('hermes:alert — '.count($findings).' CRITICAL/FAIL finding(s) but SLACK_ALERT_WEBHOOK_URL is unset; nothing posted.');
            $this->warn('SLACK_ALERT_WEBHOOK_URL is unset — logged, not posted.');

            return self::SUCCESS;
        }

        $env = (string) config('app.env');
        $count = count($findings);

        if (! $slack->send($this->fallbackText($findings, $env), $this->blocks($findings, $env))) {
            // Delivery failed (network / Slack 4xx). Do NOT persist state, so the
            // next run retries instead of treating this finding-set as "sent".
            $this->error('Slack post failed — see logs. State not updated; will retry next run.');

            return self::FAILURE;
        }

        @file_put_contents($statePath, $signature);
        $this->info('Posted Hermes alert to Slack ('.$count.' finding(s)).');

        return self::SUCCESS;
    }

    /**
     * Plain-text fallback shown in notifications / clients without Block Kit.
     *
     * @param  list<array{collector:string,severity:string,check:string,detail:string}>  $findings
     */
    private function fallbackText(array $findings, string $env): string
    {
        return '🚨 Hermes: '.count($findings)." CRITICAL/FAIL finding(s) on {$env}";
    }

    /**
     * Slack Block Kit body — a header plus one line per finding.
     *
     * @param  list<array{collector:string,severity:string,check:string,detail:string}>  $findings
     * @return list<array<string,mixed>>
     */
    private function blocks(array $findings, string $env): array
    {
        $blocks = [[
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => '🚨 Hermes: '.count($findings)." finding(s) on {$env}"],
        ]];

        foreach ($findings as $f) {
            $detail = $f['detail'] !== '' ? ' — '.$f['detail'] : '';
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*[{$f['severity']}] {$f['collector']} / {$f['check']}*{$detail}",
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => 'Run `composer hermes-status` on the server for the full report. Re-posts only when the finding-set changes.',
            ]],
        ];

        return $blocks;
    }

    /**
     * Pull findings of a given severity out of one collector's report.
     *
     * @return list<array{collector:string,severity:string,check:string,detail:string}>
     */
    private function collect(string $dir, string $arrayKey, string $sevKey, string $match): array
    {
        $path = base_path("data/agents/{$dir}/findings.json");
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data[$arrayKey] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($data[$arrayKey] as $item) {
            if (is_array($item) && ($item[$sevKey] ?? null) === $match) {
                $out[] = [
                    'collector' => $dir,
                    'severity' => $match,
                    'check' => (string) ($item['check'] ?? 'unknown'),
                    'detail' => (string) ($item['detail'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Stable fingerprint of the active finding-set (order-independent).
     *
     * @param  list<array{collector:string,severity:string,check:string,detail:string}>  $findings
     */
    private function signature(array $findings): string
    {
        $keys = array_map(
            static fn (array $f): string => $f['collector'].':'.$f['severity'].':'.$f['check'],
            $findings,
        );
        sort($keys);

        return md5(implode('|', $keys));
    }
}
