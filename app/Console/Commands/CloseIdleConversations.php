<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Close chats that have gone quiet.
 *
 * A conversation is only ever ended today by the visitor saying goodbye (the
 * runtime's end_session tool) or by a teammate closing it by hand. Everything
 * else — and that is most chats, because people close the tab — stays `active`
 * forever, which is what silently fills the takeover queue.
 *
 * This runs server-side on purpose: no browser timer survives the tab closing,
 * and the tab closing is the commonest way a chat ends. The widget's "anything
 * else?" prompt is the other half and is deliberately client-side — there is no
 * point asking someone who has already gone.
 *
 * Two rules keep it from closing something a human still cares about:
 *   - a conversation under human takeover is NEVER auto-closed (silence there
 *     means the teammate is typing or looking something up);
 *   - a visitor who asked for a human and has not been taken over yet gets
 *     `handoff_close_after_minutes` instead of the normal window.
 */
class CloseIdleConversations extends Command
{
    protected $signature = 'conversations:auto-close {--dry : Report what would close, change nothing}';

    protected $description = 'Close chats idle past the configured window (skips human takeover; longer fuse for a pending handoff).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $closeAfter = (int) config('runtime.auto_close.close_after_minutes', 120);
        $handoffAfter = (int) config('runtime.auto_close.handoff_close_after_minutes', 1440);

        // The widest window bounds the query; each row is then judged on its own.
        $cutoff = now()->subMinutes(min($closeAfter, $handoffAfter));

        $closed = $skippedTakeover = $waitingOnHuman = 0;

        Conversation::query()
            ->where('status', '!=', 'ended')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_message_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_message_at')->where('started_at', '<', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(200, function ($conversations) use (&$closed, &$skippedTakeover, &$waitingOnHuman, $closeAfter, $handoffAfter, $dry) {
                foreach ($conversations as $conversation) {
                    $meta = (array) ($conversation->meta ?? []);

                    // A teammate is live in this chat — silence is them working.
                    if (($meta['human_takeover'] ?? false) === true) {
                        $skippedTakeover++;

                        continue;
                    }

                    $pendingHandoff = ($meta['handoff_requested'] ?? false) === true;
                    $window = $pendingHandoff ? $handoffAfter : $closeAfter;
                    // These columns are annotated as strings on the model even
                    // though they cast to dates — normalise rather than assume.
                    $raw = $conversation->last_message_at ?? $conversation->started_at;
                    if ($raw === null) {
                        continue;
                    }
                    $idleSince = Carbon::parse($raw);

                    if ($idleSince->gt(now()->subMinutes($window))) {
                        if ($pendingHandoff) {
                            $waitingOnHuman++;
                        }

                        continue;
                    }

                    $closed++;

                    if ($dry) {
                        $this->line(sprintf(
                            '#%d would close — idle %d min%s',
                            $conversation->id,
                            (int) $idleSince->diffInMinutes(now()),
                            $pendingHandoff ? ', handoff never answered' : '',
                        ));

                        continue;
                    }

                    $meta['auto_closed'] = [
                        'at' => now()->toIso8601String(),
                        'idle_minutes' => (int) $idleSince->diffInMinutes(now()),
                        'reason' => $pendingHandoff ? 'idle, handoff never answered' : 'idle',
                    ];

                    $conversation->forceFill([
                        'status' => 'ended',
                        'ended_at' => now(),
                        'meta' => $meta,
                    ])->save();
                }
            });

        $this->info(sprintf(
            '%s %d conversation(s); %d under takeover left open, %d still waiting on a human.',
            $dry ? 'Would close' : 'Closed',
            $closed,
            $skippedTakeover,
            $waitingOnHuman,
        ));

        return self::SUCCESS;
    }
}
