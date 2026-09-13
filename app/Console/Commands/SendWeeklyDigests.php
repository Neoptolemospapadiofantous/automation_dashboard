<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WeeklyDigestEmail;
use App\Support\WeeklyReport;
use Illuminate\Console\Command;

/**
 * Monday-morning weekly digest to each team owner (WeeklyDigestEmail).
 *
 * Quiet-week suppression: a team with zero conversations in the window
 * gets NO email — "your agent did nothing" is churn fuel, not retention.
 * Owner-only for now; widen to admins only if someone asks.
 *
 * The numbers come from App\Support\WeeklyReport, shared with the public
 * /report/{token} page so the email and the page can never disagree. An
 * owner who switched the digest off in their notification preferences is
 * skipped like a quiet week.
 */
class SendWeeklyDigests extends Command
{
    protected $signature = 'teams:weekly-digest {--days=7 : Window size in days}';

    protected $description = 'Email each team owner a summary of last week\'s agent activity';

    public function handle(WeeklyReport $report): int
    {
        $days = max(1, (int) $this->option('days'));
        $end = now()->startOfDay();
        $start = $end->copy()->subDays($days);

        $teams = Team::query()
            ->with('owner')
            ->whereHas('agents', fn ($q) => $q->where('status', 'active'))
            ->get();

        $sent = 0;
        foreach ($teams as $team) {
            $owner = $team->owner;
            if (! $owner instanceof User) {
                continue;
            }
            if (! $owner->notificationPreferences()->wants('weekly_digest', 'mail')) {
                continue; // switched off in Settings → Notifications
            }

            $quiet = ! Conversation::query()
                ->where('team_id', $team->id)
                ->where('started_at', '>=', $start)
                ->where('started_at', '<', $end)
                ->exists();
            if ($quiet) {
                continue; // quiet week — say nothing
            }

            $stats = $report->stats($team, $days, $end);

            rescue(fn () => $owner->notify(new WeeklyDigestEmail($stats)), report: true);
            $sent++;
        }

        $this->info("Sent {$sent} digest(s) across {$teams->count()} team(s).");

        return self::SUCCESS;
    }
}
