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
                ],
                'answer' => 'Eight things: agent go-live, cold outreach, one live view, booking, invoices, connecting your tools, inbox triage, and ongoing care. Each is quoted after a free 30-minute call — a written price within 48 hours, yours to keep, and you keep the code. No list price, because no two builds are the same. What would you want built?',
            ],
            [
                'category' => 'Pricing',
                'keywords' => [
                    'price', 'prices', 'pricing', 'cost', 'costs', 'how much', 'plan', 'plans', 'quote',
                    'expensive',
                ],
                'answer' => 'The chat starts free: 1 agent, 250 credits a month, no card. Then €9 a month (1 agent, 2,500 credits), €19 (5 agents, 10,000) or €39 (5 agents, 25,000) — €39 is our most expensive plan. Cancel anytime, VAT not included. Anything we build for you has no list price; we quote it after a free call. Which are you asking about — the chat, or a build?',
            ],
            [
                'category' => 'What it does',
                'keywords' => [
                    'what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do',
                    'what is flowstack',
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
                'keywords' => [
                    'get started', 'getting started', 'sign up', 'signup', 'trial', 'free trial', 'free',
                    'how do i start', 'try',
                ],
                'answer' => 'Pick a role, upload your docs and FAQs, paste one script tag — live in about a minute. Free to start: 1 agent, 250 credits a month, no card, no expiry. Paid plans start at €9 a month, cancel anytime. Want the signup link, or something to check first?',
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
