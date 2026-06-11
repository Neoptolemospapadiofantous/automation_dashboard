<?php

namespace App\Runtime\Tools;

use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Lead;
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
 * Broadcasts LeadSaved so the kanban updates live, same as the legacy
 * Voiceflow webhook path.
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
            .'company, what they need (notes), and your 0-100 qualification score.';
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
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Qualification score: fit + intent + urgency'],
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

        $attributes = [
            'name' => trim((string) ($args['name'] ?? '')) ?: '(no name)',
            'phone' => trim((string) ($args['phone'] ?? '')) ?: null,
            'company' => trim((string) ($args['company'] ?? '')) ?: null,
            'notes' => trim((string) ($args['notes'] ?? '')) ?: null,
            // leads.score is NOT NULL — default to a neutral 50 when the
            // model didn't score (it usually does; this is the safety net).
            'score' => isset($args['score']) ? max(0, min(100, (int) $args['score'])) : 50,
            'status' => LeadStatus::New->value,
            'source' => 'chat',
            'captured' => $capturedFields,
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

        // Live kanban update — non-fatal if broadcasting is unconfigured.
        rescue(fn () => broadcast(new LeadSaved($lead->fresh()))->toOthers(), report: false);

        return [
            'status' => 'saved',
            'lead_id' => $lead->id,
            'message' => 'Lead saved. The team will follow up shortly.',
        ];
    }
}
