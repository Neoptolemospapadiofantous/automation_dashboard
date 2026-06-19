<?php

namespace App\Runtime\Support;

use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\CaptureLeadTool;

/**
 * Deterministic lead-capture backstop — the lead-capture mirror of
 * EscalateToHuman.
 *
 * The capture_lead tool is the primary path: the model calls it once it has
 * the visitor's contact details. But smaller / less reliable models (and any
 * model on an off day) sometimes gather a name + email conversationally and
 * never emit the tool call, dropping a real lead on the floor. This guarantees
 * the business-critical outcome regardless of model behavior: when a turn
 * surfaces a usable email and the model didn't capture, we capture it here.
 *
 * Reuses CaptureLeadTool::execute so dedupe (updateOrCreate on email) and the
 * LeadSaved broadcast live in ONE place — capturing again for the same visitor
 * updates the existing row rather than duplicating it, so this is safe to run
 * on every lead-capable turn.
 */
class CaptureLeadBackstop
{
    /** Pragmatic email matcher — good enough to spot a contact address in chat. */
    protected const EMAIL_RE = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public function __construct(protected CaptureLeadTool $tool) {}

    /**
     * Capture a lead from conversation text when the model didn't. Returns
     * true when a lead was captured (a usable email was found).
     *
     * @param  list<string>  $visitorTexts  visitor messages to scan (newest last)
     */
    public function capture(ConversationContext $context, array $visitorTexts): bool
    {
        $email = $this->latestEmail($visitorTexts);
        if ($email === null) {
            return false;
        }

        $vars = (array) ($context->session->variables ?? []);

        $args = array_filter([
            'name' => $this->scalar($vars, ['name', 'full_name', 'visitor_name']),
            'email' => $email,
            'phone' => $this->scalar($vars, ['phone', 'phone_number']),
            'company' => $this->scalar($vars, ['company', 'organization', 'organisation']),
            'notes' => $this->scalar($vars, ['notes', 'need', 'intent', 'interest']),
        ], fn ($v) => $v !== null && $v !== '');

        // name is required by the tool; default mirrors the tool's own fallback.
        $args['name'] = $args['name'] ?? '(no name)';

        // Best-effort: a persistence hiccup must not fail the visitor's turn.
        rescue(fn () => $this->tool->execute($args, $context), report: true);

        return true;
    }

    /**
     * Newest email mentioned across the given messages — the latest address is
     * the most likely intended contact if the visitor corrected a typo.
     *
     * @param  list<string>  $texts
     */
    protected function latestEmail(array $texts): ?string
    {
        $found = null;
        foreach ($texts as $text) {
            if (preg_match(self::EMAIL_RE, (string) $text, $m) === 1) {
                $found = $m[0];
            }
        }

        return $found;
    }

    /**
     * First non-empty scalar value among the given variable keys.
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
