<?php

/*
|--------------------------------------------------------------------------
| The suite — every module a team can see from inside the app
|--------------------------------------------------------------------------
|
| Two kinds of entry, kept apart on purpose:
|
|   app      — self-serve software. Lives in this dashboard, billed by plan.
|   services — built to order: the four services the site sells (Website,
|              Chat & voice assistant, Automations, Lead generation), quoted
|              after a free 30-minute call and invoiced separately. The
|              dashboard only points at them.
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
|   built   — built to order by us, not a switch in the app; `url` is the
|             landing page that describes it.
|
| Names and order of the four services follow SHARED.md §3.4 (2026-09-14):
| the landing's /chat-assistant page mirrors the app list by hand, so a
| module added here is added there too.
|
| `min_plan` gates app modules by plan; a team below it sees an "upgrade"
| path rather than the module. Keys are stable identifiers — the
| module_interests table stores them, so renaming one orphans its rows.
|
*/

return [
    // The one entry step for anything built to order.
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
            'min_plan' => 'starter',
        ],
        [
            'key' => 'knowledge',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Knowledge base',
            'blurb' => 'Upload documents and pages; the chat answers from them and cites where the answer came from.',
            'route' => 'knowledge.index',
            'min_plan' => 'starter',
        ],
        [
            'key' => 'leads',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Lead capture & scoring',
            'blurb' => 'Every conversation that gives a name or an email lands on the board, scored, with the transcript attached.',
            'route' => 'leads.index',
            'min_plan' => 'starter',
        ],
        [
            'key' => 'takeover',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Live takeover',
            'blurb' => 'Step into any chat as yourself. The visitor sees a human; the agent waits until you hand back.',
            'route' => 'conversations.index',
            'min_plan' => 'starter',
        ],
        [
            'key' => 'analytics',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Analytics & the Monday summary',
            'blurb' => 'Conversations, leads and capture rate per agent, plus one summary in your inbox every Monday.',
            'route' => 'agents.index',
            'min_plan' => 'starter',
        ],
        [
            'key' => 'own_key',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Your own engine key',
            'blurb' => 'Run premium engines on your own OpenAI, Anthropic or Google key — no credits spent.',
            'route' => 'own-key.index',
            'min_plan' => 'starter',
        ],
        [
            'key' => 'webhooks',
            'line' => 'app',
            'status' => 'live',
            'name' => 'Webhooks',
            'blurb' => 'Each new lead and handoff request pushed to Zapier, Make, Google Sheets or your CRM as it happens, signed with your own secret.',
            'route' => 'webhooks.index',
            'min_plan' => 'starter',
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
            'min_plan' => 'starter',
        ],
        [
            'key' => 'inbox',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'Inbox & portal enquiries',
            'blurb' => 'Enquiries arriving by email — booking portals, listing sites, your contact form — routed to the agent.',
            'route' => null,
            'min_plan' => 'starter',
        ],
        [
            'key' => 'email_automation',
            'line' => 'app',
            'status' => 'coming',
            'name' => 'Email automation',
            'blurb' => 'Follow-ups, reminders and reactivation to people who already know you, from your own address.',
            'route' => null,
            'min_plan' => 'starter',
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

        // ---- built to order: the four services ----------------------------
        [
            'key' => 'service_website',
            'line' => 'services',
            'status' => 'built',
            'name' => 'Website',
            'blurb' => 'Built or rebuilt, English or Greek, with the chat installed from day one.',
            'route' => null,
            'url' => 'https://www.flowstack.run/website',
            'min_plan' => null,
        ],
        [
            'key' => 'service_chat_voice',
            'line' => 'services',
            'status' => 'built',
            'name' => 'Chat & voice assistant',
            'blurb' => 'The chat you run here, loaded and tuned by us — plus a phone assistant that answers calls and books, built to order and never part of a plan.',
            'route' => null,
            'url' => 'https://www.flowstack.run/chat-assistant',
            'min_plan' => null,
        ],
        [
            'key' => 'service_automations',
            'line' => 'services',
            'status' => 'built',
            'name' => 'Automations',
            'blurb' => 'Your CRM, follow-ups, reminders, invoice chasers, inbox triage and live reports, running themselves around the tools you already use.',
            'route' => null,
            'url' => 'https://www.flowstack.run/automations',
            'min_plan' => null,
        ],
        [
            'key' => 'service_lead_generation',
            'line' => 'services',
            'status' => 'built',
            'name' => 'Lead generation',
            'blurb' => 'Cold email from your own address to a checked list, in your voice, replies handed to you — you approve every word.',
            'route' => null,
            'url' => 'https://www.flowstack.run/lead-generation',
            'min_plan' => null,
        ],
    ],
];
