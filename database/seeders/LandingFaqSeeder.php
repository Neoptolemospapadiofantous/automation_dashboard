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
                'answer' => 'Yes — that\'s a separate service from the chat. We find companies that fit you, email them in your voice, and hand you the replies. You approve every word. Quoted after a free 30-minute call — flowstack.run/outreach. What kind of companies do you want to reach?',
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
                'category' => 'Custom build',
                'keywords' => [
                    'custom build', 'custom-build', 'bespoke', 'own llm', 'what do you build', 'build me',
                    'build us', 'build for me', 'build for us', 'scope', 'proposal',
                    'build my website', 'build a website', 'build websites', 'website build',
                ],
                'answer' => 'We build to order: your website, agent go-live, cold outreach, email automation, booking, invoices, one live dashboard, connecting your tools, inbox triage — and ongoing care after. Take one, or hand us everything end to end. Each build is quoted after a free 30-minute call — a written price within 48 hours, and you keep the code. What would you want built?',
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
                    'own anthropic key', 'own provider key', 'bring your own', 'byok',
                ],
                'answer' => 'Yes, on the €39 Operator plan. Connect your own OpenAI or Anthropic key and your chat runs on your provider account: you pay them for the model, we charge no credits, and you get 25,000 messages a month. We store the key encrypted and stop using it the moment you disconnect it. Want me to show you where to add it?',
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
                'answer' => 'Not yet — there is no public API or SDK today. Two ways in: the website widget (one script tag) and the hosted chat page; leads and transcripts land in your dashboard, with email alerts. Need our data inside your own systems? That is a custom build, quoted after a free call. What would you connect it to?',
            ],
            [
                'category' => 'Pricing',
                'keywords' => [
                    'price', 'prices', 'pricing', 'cost', 'costs', 'how much', 'plan', 'plans', 'quote',
                    'expensive',
                ],
                'answer' => 'The chat starts free: 1 agent, 250 credits a month, no card. Then €9 a month (1 agent, 2,500 credits), €19 (5 agents, 10,000) or €39 (5 agents, 25,000) — €39 is our most expensive plan. A short chat is about 5-8 credits, so €9 buys roughly 300-500 a month. Cancel anytime, VAT not included. Builds have no list price; we quote them after a free call. Which do you mean — the chat, or a build?',
            ],
            [
                'category' => 'What it does',
                'keywords' => [
                    'what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do',
                    'what is flowstack', 'what do you actually do', 'what does flowstack do',
                ],
                'answer' => 'Three things: chat that answers your website, outreach that finds you customers, and one dashboard of your numbers. The chat is free to start and live in about a minute; the rest we build around your tools. Which of the three is closest to what you need?',
            ],
            [
                'category' => 'Book the audit',
                'keywords' => [
                    'audit', 'free audit', 'book a call', 'book an audit', 'schedule a call', 'consultation',
                ],
                'answer' => 'A free 30-minute call with a human, then a written price and scope within 48 hours — yours to keep either way. Book at flowstack.run/audit, or leave your name and email here and we\'ll send you times. Which do you prefer?',
            ],
            [
                'category' => 'Integrations',
                'keywords' => [
                    'integration', 'integrations', 'integrate', 'integrates', 'crm', 'hubspot', 'salesforce',
                    'pipedrive', 'zapier', 'shopify', 'wordpress', 'wix', 'webflow', 'calendar', 'connect to',
                    'connects to',
                ],
                'answer' => 'The widget goes on any site — Shopify, WordPress, Wix, React — it\'s one script tag. Leads and transcripts land in your Flowstack dashboard. Wiring it into your own CRM, calendar or phone system is something we build for you: quoted after a free call, and you keep the code. Which tools would you want it talking to?',
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
                'answer' => 'Create your account at app.flowstack.run/register — free to start: 1 agent, 250 credits a month, no card, no expiry. Then pick a role, upload your docs, paste one script tag, and you\'re live in about a minute. Paid plans start at €9 a month from the Billing page, cancel anytime. Want a hand with any of those steps?',
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
