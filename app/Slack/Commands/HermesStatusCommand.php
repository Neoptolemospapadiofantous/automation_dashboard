<?php

namespace App\Slack\Commands;

/**
 * `/hermes-status` — a quick read-only snapshot of the latest collector
 * reports (audit posture, system health, outdated deps). No LLM, no spend,
 * so it is open to everyone. Reads the same data/agents findings.json reports
 * the slack:digest heartbeat uses.
 */
class HermesStatusCommand implements SlashCommand
{
    public function name(): string
    {
        return 'hermes-status';
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function handle(SlashContext $ctx): string
    {
        $lines = array_filter([
            $this->line('audit-sentinel', 'Audit', ['critical', 'high', 'medium', 'low']),
            $this->line('system-check', 'System', ['pass', 'warn', 'fail']),
            $this->line('update-inspector', 'Updates', ['php_total', 'php_major', 'js_total', 'js_major']),
        ]);

        return $lines === []
            ? 'No Hermes collector reports found yet.'
            : "*Hermes status*\n".implode("\n", $lines);
    }

    /**
     * @param  list<string>  $keys
     */
    private function line(string $dir, string $label, array $keys): ?string
    {
        $path = base_path("data/agents/{$dir}/findings.json");
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return null;
        }

        $overall = (string) ($data['overall'] ?? '?');
        $parts = [];
        foreach ($keys as $k) {
            if (isset($data[$k])) {
                $parts[] = "{$k}={$data[$k]}";
            }
        }

        return "• *{$label}:* {$overall} (".implode(' · ', $parts).')';
    }
}
