<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Lead;

/**
 * The `data` block of each outbound webhook event. Kept in one place so a
 * receiver integrating against lead.captured sees the same lead shape a
 * handoff.requested event carries. Visitor-authored text is passed as
 * plain strings — receivers render it, we do not.
 */
final class WebhookPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function lead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company' => $lead->company,
            'source' => $lead->source,
            'status' => self::enumValue($lead->getAttribute('status')),
            'score' => (int) $lead->score,
            'tags' => (array) ($lead->tags ?? []),
            'notes' => $lead->notes,
            'agent_id' => $lead->getAttribute('agent_id'),
            'url' => url('/leads/'.$lead->id),
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function conversation(Conversation $conversation): array
    {
        $meta = (array) ($conversation->meta ?? []);

        return [
            'id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'lead_id' => $conversation->lead_id,
            'channel' => $conversation->getAttribute('channel'),
            'status' => $conversation->getAttribute('status'),
            'message_count' => (int) $conversation->getAttribute('message_count'),
            'labels' => (array) ($conversation->labels ?? []),
            'rating' => $conversation->getAttribute('rating'),
            'handoff_requested' => (bool) ($meta['handoff_requested'] ?? false),
            'handoff_reason' => $meta['handoff_reason'] ?? null,
            'url' => url('/conversations/'.$conversation->id),
            'started_at' => self::iso($conversation->getAttribute('started_at')),
            'ended_at' => self::iso($conversation->getAttribute('ended_at')),
        ];
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * Conversation timestamps arrive as Carbon after the cast, but a row
     * read through a raw query may carry the string — accept both.
     */
    private static function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
