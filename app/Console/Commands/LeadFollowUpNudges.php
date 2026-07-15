<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LeadFollowUpNudge;
use Illuminate\Console\Command;

/**
 * Nudge the team about leads going cold: captured, still open, never
 * contacted after the grace window, and not yet nudged. One nudge per
 * lead ever (follow_up_nudged_at) — a reminder that repeats becomes
 * noise that gets filtered.
 *
 * Recipient: the assigned rep when there is one, else the team owner.
 * "Contacted" is the operator's own Mark-contacted stamp
 * (leads.last_contacted_at) — see LeadController::markContacted.
 */
class LeadFollowUpNudges extends Command
{
    protected $signature = 'leads:follow-up-nudges {--hours=48 : Grace window after capture before nudging}';

    protected $description = 'Notify the assignee/owner about open leads never contacted after the grace window';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')));

        $stale = Lead::query()
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Qualified->value, LeadStatus::Assigned->value])
            ->whereNull('last_contacted_at')
            ->whereNull('follow_up_nudged_at')
            ->where('created_at', '<=', $cutoff)
            ->with(['assignee', 'team'])
            ->get();

        $nudged = 0;
        foreach ($stale as $lead) {
            $recipient = $lead->assignee;
            if ($recipient === null) {
                $team = $lead->team;
                $recipient = $team instanceof Team ? $team->owner : null;
            }
            if (! $recipient instanceof User) {
                continue;
            }

            // Stamp BEFORE notifying — a mail hiccup must not re-nudge
            // tomorrow, and a crash between notify and save must not spam.
            $lead->forceFill(['follow_up_nudged_at' => now()])->save();
            rescue(fn () => $recipient->notify(new LeadFollowUpNudge($lead)), report: true);
            $nudged++;
        }

        $this->info("Nudged {$nudged} of {$stale->count()} stale leads.");

        return self::SUCCESS;
    }
}
