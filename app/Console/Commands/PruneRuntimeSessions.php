<?php

namespace App\Console\Commands;

use App\Runtime\Session\SessionManager;
use Illuminate\Console\Command;

/**
 * Delete runtime sessions idle beyond the retention window (default 30
 * days — matches the embed visitor cookie TTL). Scheduled daily in
 * routes/console.php; the audit flagged the table as unbounded without it.
 */
class PruneRuntimeSessions extends Command
{
    protected $signature = 'runtime:prune-sessions {--days= : Override the retention window in days}';

    protected $description = 'Delete idle native-runtime sessions past the retention window.';

    public function handle(SessionManager $sessions): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $deleted = $sessions->prune($days);

        $this->components->info("Pruned {$deleted} runtime session(s).");

        return self::SUCCESS;
    }
}
