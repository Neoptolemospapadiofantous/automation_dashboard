<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Conversation;
use App\Models\Lead;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Console\Command;

/**
 * Partial-lead harvester, scheduled hourly.
 *
 * A visitor who tells the agent their name, company, and need but leaves
 * before sharing an email produces NO lead — capture_lead requires contact
 * details, so everything they said dies in the transcript (live-observed:
 * a bakery owner gave name + business + use case and vanished at the email
 * ask). This sweep turns those dead transcripts into partial leads: any
 * embed conversation that went quiet without a lead, whose runtime session
 * variables carry at least a name or company, becomes a low-scored
 * `chat-partial` lead the team can see on the kanban and chase manually.
 *
 * Deliberately quiet: no owner email (partials are lower-signal than real
 * captures — they surface via the kanban and the weekly digest instead).
 * Dedupe mirrors CaptureLeadTool's no-email path (team+agent+visitor), so
 * a later proper capture for the same visitor updates rather than
 * duplicates, and re-running the sweep is idempotent.
 */
class CaptureLeadPartials extends Command
{
    /** Visitor must have been quiet at least this long — they may still be typing. */
    protected const QUIET_MINUTES = 30;

    /** Don't resurrect ancient conversations; the hourly sweep only looks back this far. */
    protected const LOOKBACK_HOURS = 48;

    protected $signature = 'leads:capture-partials {--dry-run : List what would be captured without saving}';

    protected $description = 'Create partial leads from ended chats where the visitor left identity details but no contact info.';

    public function handle(): int
    {
        $conversations = Conversation::query()
            ->where('channel', 'embed')
            ->whereNull('lead_id')
            // At least greeting + one visitor message + one reply — pure
            // greeting-only opens carry nothing worth harvesting.
            ->where('message_count', '>=', 3)
            ->whereBetween('last_message_at', [
                now()->subHours(self::LOOKBACK_HOURS),
                now()->subMinutes(self::QUIET_MINUTES),
            ])
            ->with('agent')
            ->get();

        $captured = 0;
        foreach ($conversations as $conversation) {
            if ($conversation->agent === null) {
                continue;
            }

            $session = RuntimeSession::query()
                ->where('agent_id', $conversation->agent_id)
                ->where('visitor_id', $conversation->visitor_id)
                ->first();

            $vars = (array) ($session->variables ?? []);
            $name = $this->scalar($vars, ['name', 'full_name', 'visitor_name']);
            $company = $this->scalar($vars, ['company', 'organization', 'organisation', 'business']);
            if ($name === null && $company === null) {
                continue; // nothing identifying — a transcript, not a lead
            }

            $notes = $this->scalar($vars, ['notes', 'need', 'intent', 'interest', 'use_case']);

            if ($this->option('dry-run')) {
                $this->line("  would capture: {$name} / {$company} (conv #{$conversation->id})");
                $captured++;

                continue;
            }

            $lead = Lead::updateOrCreate(
                [
                    'team_id' => $conversation->team_id,
                    'agent_id' => $conversation->agent_id,
                    'email' => null,
                    'visitor_id' => $conversation->visitor_id,
                ],
                [
                    'name' => $name ?? '(no name)',
                    'phone' => $this->scalar($vars, ['phone', 'phone_number']),
                    'company' => $company,
                    'notes' => trim(($notes !== null ? $notes.' ' : '')
                        .'(partial — visitor left before sharing contact details)'),
                    // Low fixed score: identity but no contact and no stated
                    // reachable channel. The kanban sorts these below real captures.
                    'score' => 20,
                    'status' => LeadStatus::New->value,
                    'source' => 'chat-partial',
                    'captured' => array_filter(['name' => $name, 'company' => $company]),
                    'visitor_id' => $conversation->visitor_id,
                ],
            );

            $conversation->update(['lead_id' => $lead->id]);
            rescue(fn () => broadcast(new LeadSaved($lead->fresh()))->toOthers(), report: false);
            $captured++;
        }

        $this->components->info(($this->option('dry-run') ? 'Would capture ' : 'Captured ').$captured.' partial lead(s).');

        return self::SUCCESS;
    }

    /**
     * First non-empty scalar among the given variable keys (mirrors
     * CaptureLeadBackstop::scalar).
     *
     * @param  array<string, mixed>  $vars
     * @param  list<string>  $keys
     */
    protected function scalar(array $vars, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($vars[$key]) && is_scalar($vars[$key])) {
                $value = trim((string) $vars[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
