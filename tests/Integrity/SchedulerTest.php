<?php

namespace Tests\Integrity;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Category D (Integrity) — scheduler.
 *
 * `schedule:list` must run, and every command the schedule references
 * must actually be registered with Artisan — a renamed command class
 * otherwise fails silently at 03:00, not in CI.
 */
class SchedulerTest extends TestCase
{
    /** @var list<string> commands routes/console.php schedules */
    private const SCHEDULED_COMMANDS = [
        'runtime:prune-sessions',
        'credits:grant-renewals',
    ];

    public function test_schedule_list_runs(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
    }

    public function test_scheduled_commands_are_registered_with_artisan(): void
    {
        // Boot the console kernel so routes/console.php has been loaded.
        Artisan::call('list', ['--format' => 'txt']);
        $registered = Artisan::all();

        foreach (self::SCHEDULED_COMMANDS as $command) {
            $this->assertArrayHasKey(
                $command,
                $registered,
                "Scheduled command '{$command}' is not registered with Artisan"
            );
        }
    }

    public function test_schedule_contains_the_expected_commands(): void
    {
        // Resolving schedule:list above isn't enough — pin that the
        // schedule itself still references both commands.
        $this->artisan('schedule:list')->assertSuccessful();

        $schedule = $this->app->make(Schedule::class);
        $commands = array_map(
            fn ($event): string => (string) $event->command,
            $schedule->events()
        );
        $haystack = implode("\n", $commands);

        foreach (self::SCHEDULED_COMMANDS as $command) {
            $this->assertStringContainsString(
                $command,
                $haystack,
                "'{$command}' is no longer on the schedule (routes/console.php)"
            );
        }
    }
}
