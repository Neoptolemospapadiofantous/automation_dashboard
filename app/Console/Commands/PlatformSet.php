<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use Illuminate\Console\Command;

/**
 * Operator command — set a value in platform_settings.
 *
 *   php artisan platform:set founder_slots_remaining 47
 *   php artisan platform:set featured_proof "3.4× pipeline at Pendola"
 *
 *   # See all current values:
 *   php artisan platform:set --list
 *
 * Writing busts the cached /api/public/stats response so the landing
 * page picks up the change on next visitor.
 */
class PlatformSet extends Command
{
    protected $signature = 'platform:set {key? : Setting key} {value? : New value (omit to clear)} {--list : Show current settings instead of writing}';

    protected $description = 'Set or list editable platform settings (public stats / marketing scarcity).';

    public function handle(): int
    {
        if ($this->option('list') || ! $this->argument('key')) {
            return $this->listAll();
        }

        $key = (string) $this->argument('key');
        $value = $this->argument('value');

        PlatformSetting::put($key, $value);

        $this->info(sprintf('Set %s = %s', $key, $value ?? '(null)'));
        $this->line('Public stats cache busted; landing site picks up the new value on next request.');

        return self::SUCCESS;
    }

    private function listAll(): int
    {
        $rows = PlatformSetting::query()->orderBy('key')->get(['key', 'value', 'updated_at']);

        if ($rows->isEmpty()) {
            $this->line('No platform settings have been set yet.');
            $this->newLine();
            $this->line('Defaults (used when unset, see PublicStatsController):');
            $this->table(['Key', 'Default'], [
                ['founder_slots_remaining', '100'],
                ['founder_slots_total', '100'],
                ['featured_proof', '(none)'],
            ]);

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Value', 'Updated'],
            $rows->map(fn ($r) => [
                $r->key,
                $r->value ?? '(null)',
                $r->updated_at?->diffForHumans() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
