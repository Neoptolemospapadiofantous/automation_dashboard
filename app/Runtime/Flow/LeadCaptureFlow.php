<?php

namespace App\Runtime\Flow;

/**
 * The default flow every native agent runs: greet → understand what the
 * visitor needs → capture contact details → wrap up.
 *
 * greeting   first turn only; warm open, one question. Auto-advances.
 * discovery  the working state: answer questions from the KB, remember
 *            facts, qualify, and capture the lead when contact details
 *            land. capture_lead success advances to wrapup.
 * wrapup     confirm next steps, answer stragglers, close politely.
 * ended      terminal; the model only emits a short goodbye.
 */
class LeadCaptureFlow extends Flow
{
    public function initial(): string
    {
        return 'greeting';
    }

    public function states(): array
    {
        return [
            'greeting' => new State(
                prompt: 'This is the very first exchange. Greet the visitor warmly in one or two '
                    .'sentences and ask what brought them here today. Do not pitch, do not ask '
                    .'for contact details yet.',
                autoNext: 'discovery',
            ),

            'discovery' => new State(
                prompt: 'Understand what the visitor needs. Answer product questions using the '
                    .'knowledge-base context (never invent facts — if you do not know, say a '
                    .'teammate will follow up). Remember concrete facts with set_variable. When '
                    .'the visitor shows real interest or asks for follow-up, naturally ask for '
                    .'their name and email (or phone) and save them with capture_lead — do not '
                    .'be pushy, one ask per conversation. When you capture, score the lead on '
                    .'three dimensions from what they told you: fit (are they the right kind of '
                    .'customer), intent (how concrete their interest is), and urgency (how soon '
                    .'they need it) — follow the rubric in each capture_lead field. Escalate to '
                    .'request_handoff when they ask for a human or something outside your scope.',
                tools: ['query_kb', 'set_variable', 'capture_lead', 'request_handoff'],
                onToolSuccess: ['capture_lead' => 'wrapup'],
            ),

            'wrapup' => new State(
                prompt: 'Their contact details are saved. Confirm that a teammate will follow up '
                    .'shortly, answer any remaining questions from the knowledge base, and close '
                    .'warmly. Call end_session when they say goodbye or have nothing further.',
                tools: ['query_kb', 'end_session'],
                onToolSuccess: ['end_session' => 'ended'],
            ),

            'ended' => new State(
                prompt: 'The conversation is over. Reply with one short, friendly sentence noting '
                    .'the team will be in touch. Do not ask new questions.',
            ),
        ];
    }
}
