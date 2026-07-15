<?php

namespace App\Runtime\Tools;

use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LeadCapturedNotification;
use App\Runtime\Contracts\Tool;
use App\Runtime\Session\ConversationContext;

/**
 * Persist contact details the agent collected into the leads pipeline.
 *
 * Dedupe: when an email is supplied, an existing (team, agent, email)
 * lead is UPDATED instead of duplicated — visitors who chat twice
 * shouldn't litter the kanban. Without an email, every capture is a
 * fresh row (no reliable identity to merge on).
 *
 * Broadcasts LeadSaved so the kanban updates live.
 */
class CaptureLeadTool implements Tool
{
    public function name(): string
    {
        return 'capture_lead';
    }

    public function description(): string
    {
        return 'Save the visitor\'s contact details as a sales lead. Call this once you have AT '
            .'LEAST a name plus an email or phone number. Include everything you learned: '
            .'company, what they need (notes), and score the lead on three dimensions — '
            .'fit, intent, and urgency — using the rubric in each field. The three add up '
            .'to a 0-100 qualification score automatically.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Full name of the visitor'],
                'email' => ['type' => 'string', 'description' => 'Email address, if provided'],
                'phone' => ['type' => 'string', 'description' => 'Phone number, if provided'],
                'company' => ['type' => 'string', 'description' => 'Company / organization'],
                'notes' => ['type' => 'string', 'description' => 'What they need, in one or two sentences'],
                'fit' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 40,
                    'description' => 'FIT (0-40): how well they match the ideal customer. '
                        .'0-10 wrong audience / just browsing · 11-25 plausible fit, some signals · '
                        .'26-40 clearly in the target market (right role, company, use case).',
                ],
                'intent' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 35,
                    'description' => 'INTENT (0-35): how concrete their buying interest is. '
                        .'0-8 vague curiosity · 9-22 evaluating, asked real questions · '
                        .'23-35 explicit intent (pricing, demo, "how do I start").',
                ],
                'urgency' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 25,
                    'description' => 'URGENCY (0-25): how soon they need a solution. '
                        .'0-5 no timeline · 6-15 within a quarter / actively comparing · '
                        .'16-25 immediate need or stated deadline.',
                ],
                'score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Optional override: a direct 0-100 score. Prefer fit+intent+urgency instead.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $args, ConversationContext $context): array|string
    {
        $email = trim((string) ($args['email'] ?? ''));

        // leads.captured is an ARRAY cast (the legacy upsert path
        // array_merge()s it) — store the captured field map, never a bool.
        $capturedFields = array_filter([
            'name' => trim((string) ($args['name'] ?? '')) ?: null,
            'email' => $email !== '' ? $email : null,
            'phone' => trim((string) ($args['phone'] ?? '')) ?: null,
            'company' => trim((string) ($args['company'] ?? '')) ?: null,
        ]);

        [$score, $breakdown] = $this->resolveScore($args);

        $attributes = [
            'name' => trim((string) ($args['name'] ?? '')) ?: '(no name)',
            'phone' => trim((string) ($args['phone'] ?? '')) ?: null,
            'company' => trim((string) ($args['company'] ?? '')) ?: null,
            'notes' => trim((string) ($args['notes'] ?? '')) ?: null,
            'score' => $score,
            'score_breakdown' => $breakdown,
            'status' => LeadStatus::New->value,
            'source' => 'chat',
            'captured' => $capturedFields,
            // Ties the lead to the chat session that produced it — the
            // escalation contact-check and the conversation↔lead link both
            // key on this (a lead without it looks anonymous to handoff).
            'visitor_id' => $context->session->visitor_id,
        ];

        if ($email !== '') {
            $lead = Lead::updateOrCreate(
                [
                    'team_id' => $context->agent->team_id,
                    'agent_id' => $context->agent->id,
                    'email' => $email,
                ],
                $attributes,
            );
        } else {
            $lead = Lead::create($attributes + [
                'team_id' => $context->agent->team_id,
                'agent_id' => $context->agent->id,
                'email' => null,
            ]);
        }

        // Link the visitor's conversation to the lead so the transcript view
        // shows who you're talking to (and "All conversations with X" works).
        rescue(fn () => Conversation::query()
            ->where('team_id', $context->agent->team_id)
            ->where('visitor_id', $context->session->visitor_id)
            ->whereNull('lead_id')
            ->latest('id')
            ->limit(1)
            ->update(['lead_id' => $lead->id]), report: true);

        // Live kanban update — non-fatal if broadcasting is unconfigured.
        rescue(fn () => broadcast(new LeadSaved($lead->fresh()))->toOthers(), report: false);

        // Speed-to-lead: tell the owner the moment a NEW lead lands (updates
        // to an existing lead stay quiet — same visitor correcting details).
        // Assignment mail is separate and fires only when a rep is delegated.
        if ($lead->wasRecentlyCreated) {
            rescue(function () use ($lead, $context): void {
                $team = $context->agent->team;
                $owner = $team instanceof Team ? $team->owner : null;
                if ($owner instanceof User) {
                    $owner->notify(new LeadCapturedNotification($lead));
                }
            }, report: true);
        }

        return [
            'status' => 'saved',
            'lead_id' => $lead->id,
            'message' => 'Lead saved. The team will follow up shortly.',
        ];
    }

    /**
     * Resolve the final 0-100 score plus its explainable breakdown.
     *
     * Preferred path: the model returns fit (0-40), intent (0-35) and
     * urgency (0-25); we clamp each to its band and SUM them server-side
     * so the score is always the sum of its parts (no model arithmetic to
     * trust). When no sub-scores are supplied we fall back to the legacy
     * flat `score` arg, and finally to a neutral 50 (leads.score is NOT
     * NULL). The breakdown is only stored when sub-scores were given.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: int, 1: array<string, int>|null}
     */
    private function resolveScore(array $args): array
    {
        $hasBreakdown = isset($args['fit']) || isset($args['intent']) || isset($args['urgency']);

        if ($hasBreakdown) {
            $fit = max(0, min(40, (int) ($args['fit'] ?? 0)));
            $intent = max(0, min(35, (int) ($args['intent'] ?? 0)));
            $urgency = max(0, min(25, (int) ($args['urgency'] ?? 0)));

            return [
                $fit + $intent + $urgency,
                ['fit' => $fit, 'intent' => $intent, 'urgency' => $urgency],
            ];
        }

        if (isset($args['score'])) {
            return [max(0, min(100, (int) $args['score'])), null];
        }

        return [50, null];
    }
}
