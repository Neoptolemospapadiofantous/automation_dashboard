<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

/**
 * OPTIONAL, OPT-IN archival. By default the app keeps ALL conversations
 * forever (per requirement). Run this only if you deliberately want to delete
 * conversations older than a retention window to reclaim storage.
 *
 *   php artisan conversations:prune --days=365          (dry run by default)
 *   php artisan conversations:prune --days=365 --force  (actually delete)
 */
class PruneConversations extends Command
{
    protected $signature = 'conversations:prune
        {--days=365 : Delete conversations whose last activity is older than this many days}
        {--force : Actually delete (otherwise this is a dry run)}';

    protected $description = 'Optionally delete conversations older than a retention window (off by default).';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $query = Conversation::where('last_message_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No conversations older than {$days} days.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("[dry run] {$count} conversation(s) older than {$days} days would be deleted.");
            $this->line('Re-run with --force to delete them. Messages cascade automatically.');

            return self::SUCCESS;
        }

        // Cascade deletes messages via the FK; Scout removes them from the index.
        $deleted = 0;
        $query->chunkById(200, function ($conversations) use (&$deleted) {
            foreach ($conversations as $conversation) {
                $conversation->messages()->each(fn ($m) => $m->delete());
                $conversation->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} conversation(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
