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
                'answer' => 'Yes — that\'s its own service, separate from the chat. We find the companies you want as customers, write to them in your voice, and hand you the replies. You approve every word before anything sends. Scoped to your market and quoted after the free audit — flowstack.run/outreach. What kind of companies would you want to reach?',
            ],
            [
                'category' => 'What works',
                'keywords' => [
                    'what works', 'analytics', 'reporting', 'reports', 'business intelligence', 'live view',
                    'one live view', 'kpi', 'kpis', 'metrics', 'experiments', 'a/b test',
                ],
                'answer' => 'Two halves: your numbers pulled out of the tools they\'re scattered across into one live view, and the loop that keeps testing what you send and retiring what doesn\'t work. Built around your stack and quoted after the free audit — flowstack.run/what-works. What are you rebuilding by hand at the moment?',
            ],
            [
                'category' => 'Custom build',
                'keywords' => [
                    'custom build', 'custom-build', 'bespoke', 'own llm', 'what do you build', 'build me',
                    'build us', 'build for me', 'build for us', 'scope', 'proposal',
                ],
                'answer' => 'Eight things we build: agent go-live, cold outreach, one live view, booking, invoices and documents, connecting your tools, inbox triage, and ongoing care. Each is scoped to your stack and quoted after a free 30-minute audit — a written fixed-scope proposal within 48 hours, yours to keep either way, and you keep the code. There\'s no list price, because no two builds are the same. What would you want built?',
            ],
            [
                'category' => 'Pricing',
                'keywords' => [
                    'price', 'prices', 'pricing', 'cost', 'costs', 'how much', 'plan', 'plans', 'quote',
                    'expensive',
                ],
                'answer' => 'The chat starts free — 1 agent, 250 conversation credits a month, no card required. Paid plans are €9/mo (Starter, 1 agent, 2,500 credits), €19/mo (Growth, up to 5 agents, 10,000 credits) and €39/mo (Operator, 5 agents, 25,000 credits — our most expensive plan). Cancel anytime, VAT not included. Anything we build for you — outreach, reporting, integrations — has no list price: it\'s scoped to your stack and quoted after a free 30-minute audit. Which are you asking about, the chat or a build?',
            ],
            [
                'category' => 'What it does',
                'keywords' => [
                    'what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do',
                    'what is flowstack',
                ],
                'answer' => 'Three things: chat that answers every inbound on your site, outreach that finds you customers, and one live view of your numbers with a loop that keeps improving them. The chat is free to start and live in about 60 seconds; the rest we build around your stack. Which of the three is closest to what you need?',
            ],
            [
                'category' => 'Book the audit',
                'keywords' => [
                    'audit', 'free audit', 'book a call', 'book an audit', 'schedule a call', 'consultation',
                ],
                'answer' => 'The free 30-minute audit is the fastest way in: a call with a human who maps your stack, then a written, fixed-scope proposal within 48 hours — yours to keep either way. Book a slot at flowstack.run/audit, or leave your name and email here and the team will send you times. Which works better for you?',
            ],
            [
                'category' => 'Integrations',
                'keywords' => [
                    'integration', 'integrations', 'integrate', 'integrates', 'crm', 'hubspot', 'salesforce',
                    'pipedrive', 'zapier', 'shopify', 'wordpress', 'wix', 'webflow', 'calendar', 'connect to',
                    'connects to',
                ],
                'answer' => 'The widget works on any site — Shopify, WordPress, Wix, React, any domain or subdomain — it\'s one script tag. Out of the box your leads and transcripts land in the Flowstack dashboard, with email alerts. Connecting it into your own tools — a CRM like HubSpot or Pipedrive, calendars, phone systems — is one of the things we build for you, scoped and quoted after the free audit, and you keep the code. Which tools would you want it talking to?',
            ],
            [
                'category' => 'Getting started',
                'keywords' => [
                    'get started', 'getting started', 'sign up', 'signup', 'trial', 'free trial', 'free',
                    'how do i start', 'try',
                ],
                'answer' => 'You pick a role, upload your knowledge (docs, FAQs, your site), and paste one script tag — the agent is live in about 60 seconds. It\'s free to start: 1 agent and 250 conversation credits a month, no card required, and it doesn\'t expire. Paid plans begin at €9/mo when you outgrow that, cancel anytime. Want me to point you to signup, or is there something you\'d like to check first?',
            ],
            [
                'category' => 'Talk to a human',
                'keywords' => [
                    'human', 'talk to someone', 'speak to', 'representative', 'demo', 'real person',
                ],
                'answer' => 'Of course — I\'ve flagged this for the team, and someone will pick this chat up. You can also book the free 30-minute audit at flowstack.run/audit or email hello@flowstack.run. To make sure we can reach you, what\'s your name and email?',
                'escalate' => true,
            ],
        ];
    }
}
