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
                'answer' => 'Starter is €99/mo (1 agent, 2,500 conversation credits) and Operator is €399/mo (up to 5 agents, 25,000 credits). Both include every feature and cancel anytime — no lock-in. Prices exclude VAT. Full details at flowstack.run/pricing.',
            ],
            [
                'category' => 'What it does',
                'keywords' => ['what do you do', 'what is this', 'what does the agent', 'how does it work', 'what can you do', 'what is flowstack'],
                'answer' => 'Flowstack fixes three things: automations run your repetitive work, your data lands in one live view, and a chat agent on your site answers every inbound — qualifying leads or answering sales, support and onboarding questions from the knowledge you upload, with every transcript in a real-time dashboard. The chat agent goes live in about 60 seconds.',
            ],
            [
                'category' => 'Getting started',
                'keywords' => ['get started', 'getting started', 'sign up', 'signup', 'trial', 'free trial', 'free', 'how do i start', 'try'],
                'answer' => "Pick a role, upload your knowledge, and embed the widget — one agent is live in about 60 seconds, from €99/mo, cancel anytime. There's no free trial; Starter is the way to try it, and the free 30-minute audit is the way in for custom build work.",
            ],
            [
                'category' => 'Integrations',
                'keywords' => ['integration', 'integrations', 'integrate', 'integrates', 'crm', 'hubspot', 'salesforce', 'pipedrive', 'zapier', 'shopify', 'wordpress', 'wix', 'webflow', 'calendar', 'connect to', 'connects to'],
                'answer' => 'The widget drops into any site — Shopify, WordPress, Wix, React, any domain or subdomain — one script tag, or use the hosted chat page. Leads and transcripts land in your Flowstack dashboard with email alerts. Wiring the agent into your own tools — CRM (HubSpot, Pipedrive…), calendars, telephony, internal systems — is custom build work: fixed scope, we wire it, you keep the code. Book the free 30-minute audit at flowstack.run/audit, or leave your email here and we\'ll map it with you.',
            ],
            [
                'category' => 'Custom build',
                'keywords' => ['custom build', 'custom-build', 'bespoke', 'own llm', 'audit'],
                'answer' => 'The off-the-shelf agent covers about 80% of use cases. For bespoke flows, integrations (CRM, telephony, internal tools), automation pipelines, your own LLM or your own UI, we do a fixed-scope 4–6 week custom build and you keep the code. Start with the free 30-minute audit at flowstack.run/audit — you get a written, fixed-scope proposal within 48 hours, yours to keep either way.',
            ],
            [
                'category' => 'Talk to a human',
                'keywords' => ['human', 'talk to someone', 'speak to', 'representative', 'book a call', 'demo', 'real person'],
                'answer' => "Happy to connect you with a human — book the free 30-minute audit at flowstack.run/audit, email hello@flowstack.run, or tell me what you're after and I'll pass it to the team.",
            ],
        ];
    }
}
