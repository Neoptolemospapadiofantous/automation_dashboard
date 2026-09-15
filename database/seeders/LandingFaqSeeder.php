<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed the landing agent's canned-answer FAQ chips (phase-18) — the handful
 * of pricing / product / getting-started questions that are most of the
 * landing page's traffic, answered for zero tokens and zero credits.
 *
 * Idempotent: if the published config already carries exactly this set, it's
 * a no-op (no version churn). Otherwise it stages the chips into a draft and
 * publishes, using the same lifecycle the operator FAQ editor does.
 *
 * Target: LANDING_AGENT_SLUG if set, else team 1's current/first agent (the
 * landing agent in every environment — local `agent-gzap8n82p2`, prod
 * `team-1-zmdcvje9`). Run: `php artisan db:seed --class=LandingFaqSeeder`.
 */
class LandingFaqSeeder extends Seeder
{
    public function run(): void
    {
        $agent = $this->resolveLandingAgent();
        if (! $agent instanceof Agent) {
            $this->command->warn('LandingFaqSeeder: no landing agent found (set LANDING_AGENT_SLUG or create team 1\'s agent) — skipped.');

            return;
        }

        $chips = $this->chips();

        $current = AgentConfigVersion::publishedConfig($agent->id)['canned_answers'] ?? null;
        if ($current === $chips) {
            $this->command->info("LandingFaqSeeder: «{$agent->name}» already has these chips — no change.");

            return;
        }

        AgentConfigVersion::patchDraft($agent->id, ['canned_answers' => $chips]);
        $this->publishDraft($agent->id);

        $this->command->info('LandingFaqSeeder: published '.count($chips)." canned answers to «{$agent->name}» (#{$agent->id}).");
    }

    private function resolveLandingAgent(): ?Agent
    {
        $slug = (string) config('runtime.landing_agent_slug', '');
        if ($slug !== '') {
            return Agent::where('slug', $slug)->first();
        }

        $team = Team::find(1);
        if ($team instanceof Team && $team->currentAgent instanceof Agent) {
            return $team->currentAgent;
        }

        return Agent::where('team_id', 1)->orderBy('id')->first();
    }

    /**
     * Promote the agent's draft to published, archiving the previous live
     * version — mirrors AgentVersionsController::publish.
     */
    private function publishDraft(int $agentId): void
    {
        DB::transaction(function () use ($agentId): void {
            $draft = AgentConfigVersion::query()
                ->where('agent_id', $agentId)
                ->where('status', AgentConfigVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return;
            }

            AgentConfigVersion::query()
                ->where('agent_id', $agentId)
                ->where('status', AgentConfigVersion::STATUS_PUBLISHED)
                ->update(['status' => AgentConfigVersion::STATUS_ARCHIVED]);

            $draft->update([
                'status' => AgentConfigVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        });
    }

    /**
     * The chip set, authored from docs/landing-kb. Each is a category (the chip
     * label + an exact match) plus keywords that route typed questions, and a
     * short factual answer served verbatim.
     *
     * ORDER IS LOAD-BEARING. CannedAnswers walks this list and the FIRST MATCH
     * WINS, so specific services must sit ahead of generic ones: 'Pricing' with
     * a `how much` keyword used to swallow "how much does a custom build cost?"
     * and answer it with plan prices. Likewise 'Book the audit' must precede
     * 'Getting started' or the bare `free` keyword steals "free audit".
     *
     * KEEP THIS IN SYNC WITH PROD. This seeder is the source of truth and it
     * REPLACES the published set wholesale — running a stale copy silently
     * deletes answers. That has already happened once: the 9-chip v13 (the
     * 2026-08-11 rewrite that stopped the chat denying we do lead generation)
     * was wiped back to 7 chips by a v14 seeder run two days later. This set is
     * v13 merged with v14's escalate flag and the 2026-08-27 prices.
     *
     * ANSWER LENGTH IS PART OF THE COPY. These are the cheapest and most-read
     * turns on the site, and they render in a chat bubble, not on a page —
     * they were cut from 60-90 words to 38-68 on 2026-08-27 to match the
     * landing's TL;DR rewrite. Keep them short, plain, and ending on a
     * forward-moving question; the detail lives in docs/landing-kb, which the
     * LLM path retrieves when a visitor wants more.
     *
     * @return list<array{category: string, keywords: list<string>, answer: string, escalate?: bool}>
     */
    private function chips(): array
    {
        return [
            [
                'category' => 'Outreach',
                'keywords' => [
                    'outreach', 'cold outreach', 'cold email', 'lead generation', 'lead gen', 'leadgen',
                    'prospecting', 'find customers', 'find me customers', 'get me customers', 'get customers',
                    'more customers', 'win customers', 'new customers', 'customer acquisition', 'email campaign',
                ],
                'answer' => 'Yes — that\'s a separate service from the chat. We find companies that fit you, email them in your voice, and hand you the replies. You approve every word. Quoted after a free 30-minute call — flowstack.run/lead-generation. What kind of companies do you want to reach?',
            ],
            [
                'category' => 'What works',
                'keywords' => [
                    'what works', 'analytics', 'reporting', 'reports', 'business intelligence', 'live view',
                    'one live view', 'kpi', 'kpis', 'metrics', 'experiments', 'a/b test',
                ],
                'answer' => 'Two things: all your numbers in one dashboard, and a monthly loop that tests what you send and keeps what works. Built around the tools you already use, quoted after a free 30-minute call — flowstack.run/what-works. What are you rebuilding by hand right now?',
            ],
            [
                // Sits ahead of 'Custom build' so "can you do booking" is read as an
                // AVAILABILITY question before it can be read as a build one. It exists
                // because the LLM path answered "we don't have booking yet" and then
                // offered to fetch a human — routing a routine product question into the
                // escalation path, which rings the founder's phone. A chip matches first
                // and cannot escalate (same reason the BYOK chip was added on 08-27).
                //
                // Keywords are PHRASE-LEVEL and deliberately exclude bare 'booking' and
                // bare 'whatsapp': "set up booking for me" is built to order, and "can I
                // contact you on WhatsApp" is a contact question. Also excluded, after
                // probing: 'email automation', 'send follow-ups', 'send reminders' and
                // 'live dashboard' — those name services we SELL TODAY (the live
                // /automations page), and routing them here would answer "not yet"
                // about work we already do. 'one live view' is redundant:
                // 'What works' sits ahead of this and wins it.
                'category' => "What's coming",
                'keywords' => [
                    'can you do booking', 'do you do booking', 'do you have booking',
                    'is booking available', 'can the app do booking', 'can the chat book',
                    'book appointments',
                    'whatsapp channel', 'whatsapp integration', 'answer on whatsapp', 'work on whatsapp',
                    "what's coming", 'whats coming', 'coming soon', 'roadmap', 'not yet available',
                ],
                'answer' => 'Not in the app yet — booking, WhatsApp, inbox and portal enquiries, email automation and the live dashboard are all on the way. In your dashboard, open Suite and press Request on the ones you need; we build in that order and email you when yours is ready. Need it now? We build it to order after a free 30-minute call. Which one are you after?',
            ],
            [
                'category' => 'Custom build',
                'keywords' => [
                    'custom build', 'custom-build', 'bespoke', 'own llm', 'what do you build', 'build me',
                    'build us', 'build for me', 'build for us', 'scope', 'proposal',
                    'build my website', 'build a website', 'build websites', 'website build',
                    // 'the studio' stays as a keyword although the name was retired from
                    // public copy on 2026-09-14 — visitors who saw it still ask. Bare
                    // 'studio' is NOT one: a yoga or dance studio would land here.
                    // Bare 'package'/'packages' were tried and REJECTED the same day: "what's in the
                    // €19.99 package?" is a subscription question and Custom build sits ahead of Pricing.
                    'the studio', 'done for you', 'do it for me', 'do it for us',
                ],
                'answer' => 'We build four things to order: your website; a chat and voice assistant, including a phone assistant that answers calls and books; automations for your CRM, follow-ups and invoices; and lead generation by cold email. One step to start: a free 30-minute call at flowstack.run/audit, then a written fixed price within 48 hours. You keep everything built. Which one are you after?',
            ],
            [
                // Placed ahead of 'Pricing' deliberately: first match wins, and
                // "how much does it cost to bring my own key?" otherwise lands on
                // Pricing and gets a correct-but-thin answer. Measured against the
                // live matcher: 5 of 8 natural phrasings previously reached the LLM,
                // where the low-confidence backstop escalates some of the time — and
                // an escalation rings the founder's phone. A canned answer cannot
                // escalate and costs no tokens.
                //
                // KEYWORDS ARE DELIBERATELY LONG. Bare 'key', 'api' and 'provider'
                // were each tested and REJECTED: 'key' collides with "where do I get
                // my API key for the widget?" and "what are the key features?",
                // 'api' with "do you have an API I can call?", 'provider' with
                // "which provider do you use?". Same trap as 'custom' matching
                // "customer" and bare 'build' swallowing "build my knowledge base".
                'category' => 'Your own key',
                'keywords' => [
                    'own key', 'my own key', 'own api key', 'own openai key',
                    'own anthropic key', 'own provider key', 'own google key',
                    'own gemini key', 'bring your own', 'byok',
                ],
                'answer' => 'Yes, on any paid plan. Every plan includes Flowstack Core on credits; the premium engines — Claude, GPT-5, Gemini — run on your own OpenAI, Anthropic or Google API key instead, at no credits, against your plan\'s monthly message allowance. Connect it in Settings → Your own API key; we verify it before saving. Which provider do you use?',
            ],
            [
                // Sits after "Your own key" so "can I use my own API key?" keeps
                // landing there; bare 'api' is deliberately NOT a keyword — it
                // would swallow "where do I get my API key for the widget?".
                'category' => 'Public API',
                'keywords' => [
                    'public api', 'rest api', 'api access', 'api endpoint', 'api endpoints', 'an api',
                    'your api', 'the api', 'api docs', 'api documentation', 'developer api', 'sdk', 'graphql',
                ],
                'answer' => 'Not yet — there is no public API or SDK today. What there is: outbound webhooks on paid plans, pushing each new lead and handoff request to Zapier, Make, Google Sheets or your CRM, signed with your secret — plus the widget and the hosted chat page. Need more of our data inside your own systems? A custom build, quoted after a free call. What would you connect it to?',
            ],
            [
                'category' => 'Pricing',
                'keywords' => [
                    'price', 'prices', 'pricing', 'cost', 'costs', 'how much', 'plan', 'plans', 'quote',
                    'expensive',
                ],
                'answer' => 'Two plans: €19.99 a month (5 agents, 10,000 credits) or €39.99 (5 agents, 25,000 credits, best rate) — and paying yearly (€383.90, 20% off) includes a free website build. A short chat is about 5-8 credits, so €19.99 buys roughly 1,200-2,000 chats. Cancel anytime, VAT not included. Builds are quoted after a free 30-minute call. The chat, or a build?',
            ],
            [
                'category' => 'What it does',
                'keywords' => [
                    'what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do',
                    'what is flowstack', 'what do you actually do', 'what does flowstack do',
                ],
                // The site's frame since 2026-09-13: FOUR things, named as the
                // header names them. The website chat is the only self-serve
                // part; the phone assistant is built to order, never "in the app".
                'answer' => 'Four things: your website, built or rebuilt; a chat and voice assistant that answers every enquiry from your own knowledge — the website chat runs from €19.99 a month and is live in a minute, the phone assistant is built to order; automations for your CRM, follow-ups and invoices; and lead generation — cold email, plus call-back and SMS to people who enquired. Take one, or the lot. Which one are you after?',
            ],
            [
                'category' => 'Book the audit',
                'keywords' => [
                    'audit', 'free audit', 'book a call', 'book an audit', 'schedule a call', 'consultation',
                    'leak report', 'losing customers', 'where am i losing',
                ],
                // 'leak report' stays as a keyword for the same reason as 'the studio':
                // the name was retired on 2026-09-14 but people who saw it still ask.
                'answer' => 'One step: book a free 30-minute call, in Greek or English. You show us the work you want off your plate; within 48 hours you get a written fixed price — what ships, how long, how much — yours to keep whether or not you hire us. Book at flowstack.run/audit, or leave your email here and we\'ll set it up. Which do you prefer?',
            ],
            [
                'category' => 'Integrations',
                // Webhooks shipped 2026-09-13 (Settings → Webhooks, paid plans):
                // the honest answer changed from "we build it for you" to
                // "push it yourself, we build the deeper wiring". 'make.com'
                // rather than bare 'make' — "make it answer in Greek" must not
                // land here.
                'keywords' => [
                    'integration', 'integrations', 'integrate', 'integrates', 'crm', 'hubspot', 'salesforce',
                    'pipedrive', 'zapier', 'make.com', 'google sheets', 'webhook', 'webhooks', 'shopify',
                    'wordpress', 'wix', 'webflow', 'calendar', 'connect to', 'connects to', 'send leads to',
                    'push leads',
                ],
                'answer' => 'The widget goes on any site — Shopify, WordPress, Wix, React — one script tag. Leads and transcripts land in your dashboard. On a paid plan, webhooks push each new lead and handoff request to Zapier, Make, Google Sheets or your CRM the moment it happens, signed with your own secret. Deeper wiring — calendars, phone, internal systems — we build for you, quoted after a free call. Which tools would you connect?',
            ],
            [
                'category' => 'Getting started',
                // Buy-intent lands here too (a real visitor asked "the link to
                // buy it" and got "I don't have a direct buy link" — conv #169,
                // 2026-08-30). Bare 'link'/'account'/'subscription' stay OFF the
                // list: they steal "link to your LinkedIn", settings questions
                // and the BYOK chip's "own subscription" phrasings.
                'keywords' => [
                    'get started', 'getting started', 'sign up', 'signup', 'trial', 'free trial', 'free',
                    'how do i start', 'try', 'buy', 'subscribe', 'checkout', 'register',
                    'create an account', 'pay', 'sign-up link', 'signup link',
                    // The exact first turn from conv #169. ONLY the full phrase
                    // is safe — 'the link'/'send me the link' steal legitimate
                    // audit/privacy/terms link questions; those variants belong
                    // to the LLM path, which faq.md now grounds correctly.
                    'give me the link', 'purchase', 'purchasing',
                ],
                'answer' => 'Create your account at app.flowstack.run/register, pick a plan — €19.99 or €39.99 a month, cancel anytime — upload your own docs and FAQs, and the chat is answering on your site in about a minute with one script tag. Prefer to see it first? Book the free 30-minute call. Ready to try it?',
            ],
            [
                'category' => 'Talk to a human',
                'keywords' => [
                    'human', 'talk to someone', 'speak to', 'representative', 'demo', 'real person',
                ],
                'answer' => 'Of course — I\'ve flagged this for the team and someone will pick up this chat. You can also book a free call at flowstack.run/audit or email hello@flowstack.run. What\'s your name and email, so we can reach you?',
                'escalate' => true,
            ],
        ];
    }
}
