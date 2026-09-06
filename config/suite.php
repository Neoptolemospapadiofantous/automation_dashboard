<?php

/*
|--------------------------------------------------------------------------
| The suite — every module a team can see from inside the app
|--------------------------------------------------------------------------
|
| Two lines, kept apart on purpose (founder decision, 2026-09-06):
|
|   app    — self-serve software. Lives in this dashboard, billed by plan.
|   studio — the done-for-you service line, sold locally in Cyprus and
|            invoiced by Flowstack Studio. The dashboard only points at it.
|
| Statuses, and the framing rule that goes with them:
|
|   live    — shipped and usable today; `route` names where it lives.
|   coming  — NOT available. The page says so in those words and offers a
|             "request it" action that records interest per team. Nothing
|             here may describe a coming module as if it works — the copy
|             rule sitewide is that claims match what ships (see the
|             landing's copy-accuracy rule). Interest counts are the only
|             honest demand signal for an unbuilt module.
|   studio  — done for you by the Studio, not a switch in the app.
|
| `min_plan` gates app modules by plan; a team below it sees an "upgrade"
| path rather than the module. Keys are stable identifiers — the
| module_interests table stores them, so renaming one orphans its rows.
|
*/

return [
    'studio_url' => 'https://www.flowstack.run/studio',
    'audit_url' => 'https://www.flowstack.run/audit',

    'modules' => [
        // ---- the app -----------------------------------------------------
        [
            'key' => 'chat',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Website chat',
            'blurb' => 'Answers visitors from your own material, in their language, on your site and on a hosted chat page.',
            'route' => 'install.index',
            'min_plan' => 'free',
        ],
        [
            'key' => 'knowledge',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Knowledge base',
            'blurb' => 'Upload documents and pages; the chat answers from them and cites where the answer came from.',
            'route' => 'knowledge.index',
            'min_plan' => 'free',
        ],
        [
            'key' => 'leads',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Lead capture & scoring',
            'blurb' => 'Every conversation that gives a name or an email lands on the board, scored, with the transcript attached.',
            'route' => 'leads.index',
            'min_plan' => 'free',
        ],
        [
            'key' => 'takeover',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Live takeover',
            'blurb' => 'Step into any chat as yourself. The visitor sees a human; the agent waits until you hand back.',
            'route' => 'conversations.index',
            'min_plan' => 'free',
        ],
        [
            'key' => 'analytics',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Analytics & the Monday summary',
            'blurb' => 'Conversations, leads and capture rate per agent, plus one summary in your inbox every Monday.',
            'route' => 'agents.index',
            'min_plan' => 'free',
        ],
        [
            'key' => 'own_key',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Your own engine key',
            'blurb' => 'Run premium engines on your own OpenAI, Anthropic or Google key — no credits spent.',
            'route' => 'own-key.index',
            'min_plan' => 'growth',
        ],
        [
            'key' => 'booking',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'Booking & appointments',
            'blurb' => 'The chat checks your calendar, books the slot, and sends the confirmation and reminder.',
            'route' => null,
            'min_plan' => 'starter',
        ],
        [
            'key' => 'whatsapp',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'WhatsApp channel',
            'blurb' => 'The same agent answering on your WhatsApp Business number, with the transcript on the same board.',
            'route' => null,
            'min_plan' => 'growth',
        ],
        [
            'key' => 'inbox',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'Inbox & portal enquiries',
            'blurb' => 'Enquiries arriving by email — booking portals, listing sites, your contact form — routed to the agent.',
            'route' => null,
            'min_plan' => 'growth',
        ],
        [
            'key' => 'email_automation',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'Email automation',
            'blurb' => 'Follow-ups, reminders and reactivation to people who already know you, from your own address.',
            'route' => null,
            'min_plan' => 'growth',
        ],
        [
            'key' => 'live_view',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'One live view',
            'blurb' => 'Your numbers from the tools they are scattered across, in one dashboard that refreshes itself.',
            'route' => null,
            'min_plan' => 'pro',
        ],

        // ---- the studio --------------------------------------------------
        [
            'key' => 'studio_leak_report',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'The Leak Report',
            'blurb' => 'Free. One page on where you are losing customers this month, in five working days, ending in a call.',
            'route' => null,
            'min_plan' => null,
        ],
        [
            'key' => 'studio_enquiry',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'Never Miss an Enquiry',
            'blurb' => 'Site, WhatsApp and inbox enquiries answered in under a minute and booked — set up and watched by us.',
            'route' => null,
            'min_plan' => null,
        ],
        [
            'key' => 'studio_calendar',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'Fill the Calendar',
            'blurb' => 'Everything above, plus we go and find the customers — and change what we do each month on the numbers.',
            'route' => null,
            'min_plan' => null,
        ],
        [
            'key' => 'studio_website',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'Website build',
            'blurb' => 'A fast, simple site with the chat installed from day one. English or Greek.',
            'route' => null,
            'min_plan' => null,
        ],
        [
            'key' => 'studio_outreach',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'Cold outreach engine',
            'blurb' => 'Your own sending domain, a verified list, sequences in your voice, replies handed to you.',
            'route' => null,
            'min_plan' => null,
        ],
        [
            'key' => 'studio_tools',
            'line' => 'studio',
            'status' => 'studio',
            'name' => 'Invoices, inbox triage, connecting your tools',
            'blurb' => 'The back-office busywork, automated around the tools you already use.',
            'route' => null,
            'min_plan' => null,
        ],
    ],
];
