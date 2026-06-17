<?php

namespace App\Console\Commands;

use App\Notifications\HermesAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Reads the latest Hermes collector reports and emails the operator when there
 * are CRITICAL (audit-sentinel) or FAIL (system-check) findings.
 *
 * Dedupes via storage/app/hermes-alert-state.json: only sends when the set of
 * active CRITICAL/FAIL findings changes, so a standing condition doesn't email
 * on every run. When the set clears, state resets so a later recurrence alerts
 * again. Wired via ->then() right after the collectors in routes/console.php;
 * also runnable by hand (`php artisan hermes:alert [--force]`) for validation.
 */
class HermesAlertCommand extends Command
{
    protected $signature = 'hermes:alert {--force : Send even if the finding-set is unchanged}';

    protected $description = 'Email the operator about Hermes CRITICAL/FAIL findings (deduped).';

    public function handle(): int
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

        $email = (string) config('hermes.alert_email');
        if ($email === '') {
            Log::warning('hermes:alert — '.count($findings).' CRITICAL/FAIL finding(s) but HERMES_ALERT_EMAIL is unset; no email sent.');
            $this->warn('HERMES_ALERT_EMAIL is unset — logged, not emailed.');

            return self::SUCCESS;
        }

        Notification::route('mail', $email)->notify(
            new HermesAlertNotification($findings, (string) config('app.env'))
        );

        @file_put_contents($statePath, $signature);
        $this->info('Queued Hermes alert to '.$email.' ('.count($findings).' finding(s)).');

        return self::SUCCESS;
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
