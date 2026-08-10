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
     * The starter chip set, authored from docs/landing-kb. Each is a category
     * (the chip label + an exact match) plus keywords that route typed
     * questions, and a short factual answer served verbatim.
     *
     * @return list<array{category: string, keywords: list<string>, answer: string}>
     */
    private function chips(): array
    {
        return [
            [
                'category' => 'Pricing',
                'keywords' => ['price', 'prices', 'pricing', 'cost', 'costs', 'how much', 'plan', 'plans'],
                'answer' => 'Two plans: Starter at €99/mo (1 agent, 2,500 conversation credits) and Operator at €399/mo (up to 5 agents, 25,000 credits). Every feature is in both, cancel anytime, no lock-in — prices exclude VAT, full details at flowstack.run/pricing. What would you be using it for — support, sales, or capturing leads?',
            ],
            [
                'category' => 'What it does',
                'keywords' => ['what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do', 'what is flowstack'],
                'answer' => 'In short: automations take over your repetitive work, your numbers land in one live view, and a chat agent on your site answers every visitor — sales, support, lead qualification — from the knowledge you upload. Every transcript lands in a real-time dashboard, and the chat goes live in about 60 seconds. What kind of work are you looking to hand off?',
            ],
            [
                'category' => 'Book the audit',
                'keywords' => ['audit', 'free audit', 'book a call', 'book an audit', 'schedule a call', 'consultation'],
                'answer' => 'The free 30-minute audit is the fastest way in: a call with a human who maps your stack, then a written, fixed-scope proposal within 48 hours — yours to keep either way. Book a slot at flowstack.run/audit, or leave your name and email here and the team will send you times. Which works better for you?',
            ],
            [
                'category' => 'Getting started',
                'keywords' => ['get started', 'getting started', 'sign up', 'signup', 'trial', 'free trial', 'free', 'how do i start', 'try'],
                'answer' => "You pick a role, upload your knowledge (docs, FAQs, your site), and paste one script tag — the agent is live in about 60 seconds, from €99/mo, cancel anytime. There's no free trial; Starter is the way to try it. Want me to point you to signup, or is there something you'd like to check first?",
            ],
            [
                'category' => 'Integrations',
                'keywords' => ['integration', 'integrations', 'integrate', 'integrates', 'crm', 'hubspot', 'salesforce', 'pipedrive', 'zapier', 'shopify', 'wordpress', 'wix', 'webflow', 'calendar', 'connect to', 'connects to'],
                'answer' => "The widget works on any site — Shopify, WordPress, Wix, React, any domain or subdomain — it's one script tag. Out of the box your leads and transcripts land in the Flowstack dashboard, with email alerts. Connecting it into your own tools — a CRM like HubSpot or Pipedrive, calendars, phone systems — is something we wire for you as a custom build, and you keep the code. Which tools would you want it talking to?",
            ],
            [
                'category' => 'Custom build',
                'keywords' => ['custom build', 'custom-build', 'bespoke', 'own llm'],
                'answer' => 'The self-serve agent covers the standard 80% of use cases. For the rest — bespoke flows, integrations with your stack, your own LLM or your own UI — we do a fixed-scope custom build, usually 4–6 weeks, and you keep the code. It starts with a free 30-minute audit at flowstack.run/audit: you get a written, fixed-scope proposal within 48 hours, yours to keep either way. What would you want built?',
            ],
            [
                'category' => 'Talk to a human',
                'keywords' => ['human', 'talk to someone', 'speak to', 'representative', 'demo', 'real person'],
                'answer' => 'Of course. Fastest ways: book the free 30-minute audit at flowstack.run/audit, email hello@flowstack.run, or leave your name and email here and the team will get back to you. Which works best for you?',
            ],
        ];
    }
}
