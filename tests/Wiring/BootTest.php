<?php

namespace Tests\Wiring;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Category E (Wiring) — E6 boot.
 *
 * Catches provider exceptions, circular dependencies and bad config at
 * the earliest possible moment, plus the duplicate-top-level-config-key
 * bug class (PHP silently keeps the LAST duplicate array key — we
 * shipped that twice, with 'tiers' and 'safety' in config/runtime.php).
 */
class BootTest extends TestCase
{
    public function test_the_application_boots_with_all_providers(): void
    {
        $this->assertTrue($this->app->isBooted());
    }

    public function test_artisan_about_runs(): void
    {
        // `about` touches config, drivers and every provider's bindings.
        $this->artisan('about')->assertSuccessful();
    }

    public function test_no_config_file_has_duplicate_top_level_keys(): void
    {
        $failures = [];

        foreach (File::files(config_path()) as $file) {
            $seen = [];
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                // Top-level keys in Laravel config files sit at exactly a
                // 4-space indent: `    'key' => ...`. Nested keys are
                // indented deeper, so this is a faithful top-level parse.
                if (preg_match("/^ {4}['\"]([A-Za-z0-9_.-]+)['\"] =>/", rtrim($line), $matches)) {
                    $seen[$matches[1]][] = $number + 1;
                }
            }

            foreach ($seen as $key => $lines) {
                if (count($lines) > 1) {
                    $failures[] = sprintf(
                        "%s: top-level key '%s' declared %d times (lines %s) — PHP silently keeps the last one",
                        $file->getFilename(),
                        $key,
                        count($lines),
                        implode(', ', $lines)
                    );
                }
            }
        }

        $this->assertSame([], $failures, "Duplicate top-level config keys:\n".implode("\n", $failures));
    }

    public function test_duplicate_key_guard_actually_parses_runtime_config(): void
    {
        // Self-test for the guard above: if the regex stops matching the
        // file layout, the duplicate check passes vacuously. runtime.php
        // must yield its known top-level sections.
        $keys = [];
        foreach (file(config_path('runtime.php')) ?: [] as $line) {
            if (preg_match("/^ {4}['\"]([A-Za-z0-9_.-]+)['\"] =>/", rtrim($line), $matches)) {
                $keys[] = $matches[1];
            }
        }

        foreach (['llm', 'embeddings', 'rag', 'tiers', 'safety', 'session'] as $section) {
            $this->assertContains($section, $keys, "Guard regex no longer sees runtime.{$section}");
        }
    }
}
