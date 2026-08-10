<?php

namespace App\Http\Controllers;

use App\Actions\Agents\CreateAgent;
use App\Jobs\IngestOnboardingWebsite;
use App\Lifecycle\OnboardingState;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Runtime\LLM\LlmRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The (now single-step) onboarding wizard.
 *
 * Two-step wizard (intro → done): the user clicks "Set up my agent",
 * CreateAgent provisions a native-runtime agent instantly (nothing
 * external to allocate), redirects to Done.
 */
class OnboardingController extends Controller
{
    /**
     * Step 1 — explain the one-click setup. POST'ing to startAgent below
     * provisions from the pool and lands on Done immediately.
     */
    public function intro(Request $request): Response|RedirectResponse
    {
        if (($jump = $this->jumpIfFurtherAlong($request)) !== null) {
            return $jump;
        }

        $tiers = [];
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            $tiers[] = [
                'key' => $key,
                'label' => (string) ($tier['label'] ?? ucfirst($key)),
                'description' => (string) ($tier['description'] ?? ''),
                'model' => (string) ($tier['model'] ?? ''),
                'credits_per_message' => (int) ($tier['credits_per_message'] ?? 1),
                'available' => LlmRouter::providerAvailable((string) ($tier['provider'] ?? 'anthropic')),
            ];
        }

        return Inertia::render('Onboarding/Intro', [
            'team' => ['id' => $request->user()->currentTeam->id, 'name' => $request->user()->currentTeam->name],
            'tiers' => $tiers,
        ]);
    }

    public function startAgent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Onboarding profile capture — segments customers for
            // marketing + lets us tailor in-app prompts. All optional;
            // unanswered questions stay null in team.profile.
            'industry' => ['nullable', 'string', 'in:saas,ecommerce,agency,services,real_estate,healthcare,education,other'],
            'use_case' => ['nullable', 'string', 'in:lead_capture,customer_support,scheduling,qualification,faq,other'],
            'team_size' => ['nullable', 'string', 'in:solo,2-5,6-20,21-100,100+'],
            'website' => ['nullable', 'url', 'max:255'],
            // Quality tier — couples model to credit price (see runtime.tiers).
            'model_tier' => ['nullable', 'string', 'in:'.implode(',', $this->availableTierKeys())],
        ]);

        $team = $request->user()->currentTeam;

        // Save profile alongside provisioning. Skip when all fields are
        // empty (returning user re-clicking start) so we don't overwrite
        // an already-saved profile with nulls.
        $profile = [];
        foreach (['industry', 'use_case', 'team_size', 'website'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $profile[$field] = $data[$field];
            }
        }
        if ($profile !== []) {
            $existing = is_array($team->getAttribute('profile')) ? $team->getAttribute('profile') : [];
            $team->forceFill(['profile' => array_merge($existing, $profile)])->save();
        }

        // Re-click protection. If the user double-submits, pick up the
        // already-created agent rather than burning another pool slot.
        // Looks for DRAFT-or-ACTIVE because managed signups land ACTIVE
        // immediately; a previous click would have provisioned already.
        $existing = $team->agents()
            ->whereIn('status', [Agent::STATUS_DRAFT, Agent::STATUS_ACTIVE])
            ->latest()
            ->first();
        $agent = $existing ?: (new CreateAgent)->execute($team, $data['name'] ?? 'Default agent');

        // Seed a published v1 shaped by the wizard: goal-specific starter
        // instructions plus a static greeting (served verbatim at launch —
        // no LLM call, instant open), alongside the chosen quality tier.
        // This is real published behavior, so it honestly ticks the
        // checklist's 'Publish behavior' step. Re-clicks never overwrite
        // an existing agent's config.
        $tier = (string) ($data['model_tier'] ?? AgentConfigVersion::defaultTier());
        if ($existing === null) {
            [$instructions, $greeting] = $this->starterTemplate((string) ($data['use_case'] ?? 'other'), (string) $team->getAttribute('name'));
            AgentConfigVersion::create([
                'agent_id' => $agent->id,
                'version' => 1,
                'status' => AgentConfigVersion::STATUS_PUBLISHED,
                'config' => ['instructions' => $instructions, 'greeting' => $greeting, 'model_tier' => $tier],
                'published_at' => now(),
            ]);
        }

        // Auto-ingest the website they told us about so the agent can answer
        // real product questions from its very first conversation. Queued —
        // onboarding never blocks on an external fetch, and failures are
        // silent (the KB page still offers manual ingestion).
        if ($existing === null && isset($profile['website'])) {
            IngestOnboardingWebsite::dispatch($agent->id, (string) $profile['website']);
        }

        // Managed signups are activated atomically by CreateAgent — no
        // credential-paste step. Go straight to Done.
        return redirect()->route('onboarding.done');
    }

    /**
     * Goal-shaped starter behavior published as config v1, so a brand-new
     * agent acts like the goal the customer picked instead of a blank slate.
     * Instructions ride the cached system-prompt prefix; the greeting is
     * served statically at launch. Operators refine both on the Versions page.
     *
     * @return array{0: string, 1: string} [instructions, greeting]
     */
    protected function starterTemplate(string $useCase, string $company): array
    {
        return match ($useCase) {
            'lead_capture' => [
                'Your primary goal is turning interested visitors into leads. Answer their '
                    .'questions helpfully, and as soon as someone shows genuine interest, ask for '
                    .'their name and email so the team can follow up. Never let an interested '
                    .'visitor leave without offering to take their details.',
                "Hi! Welcome to {$company} — happy to help. What brought you here today?",
            ],
            'customer_support' => [
                'Your primary goal is resolving support questions from the knowledge base. If you '
                    .'cannot resolve an issue confidently, or the visitor seems frustrated, hand '
                    .'off to a human right away instead of guessing. Take contact details whenever '
                    .'a follow-up is needed.',
                "Hi, you've reached {$company} support. What can I help you with?",
            ],
            'scheduling' => [
                'Your primary goal is booking appointments. When a visitor wants to meet, collect '
                    .'their name, email, and preferred day and time, then confirm the team will '
                    .'send a confirmation. Keep the exchange short and friendly.',
                "Hi! Welcome to {$company}. Would you like to book a time with us, or do you have a question first?",
            ],
            'qualification' => [
                'Your primary goal is qualifying prospects. Ask brief questions to understand what '
                    .'they need, their timeline, and their situation before taking contact details, '
                    .'and score each lead carefully on fit, intent, and urgency.',
                "Hi! Welcome to {$company}. Tell me a bit about what you're looking for and I'll point you the right way.",
            ],
            'faq' => [
                'Your primary goal is answering questions accurately from the knowledge base. Keep '
                    .'answers short and to the point. Only ask for contact details when the visitor '
                    .'wants a follow-up, and hand off to a human when the knowledge base has no answer.',
                "Hi! I can answer questions about {$company}. What would you like to know?",
            ],
            default => [
                'Help visitors with whatever they need: answer questions from the knowledge base, '
                    .'take contact details when a follow-up makes sense, and hand off to a human '
                    .'when a question is beyond you.',
                "Hi! Welcome to {$company}. How can I help today?",
            ],
        };
    }

    public function done(Request $request): Response|RedirectResponse
    {
        $state = OnboardingState::for($request->user());

        if ($state !== OnboardingState::Complete) {
            return redirect()->route($state->nextRoute() ?? 'onboarding.intro');
        }

        $agent = $request->user()->currentTeam->currentAgent;

        return Inertia::render('Onboarding/Done', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                // slug powers the featured install snippet block on the Done
                // page. Without it the snippet computed property is empty and
                // the whole "Drop this on your website" callout silently hides.
                'slug' => $agent->slug,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    protected function availableTierKeys(): array
    {
        $keys = [];
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            if (LlmRouter::providerAvailable((string) ($tier['provider'] ?? 'anthropic'))) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * If the user lands on Intro but OnboardingState says they belong
     * further along, bounce forward. Refreshing any wizard URL always
     * lands on the right step.
     */
    protected function jumpIfFurtherAlong(Request $request): ?RedirectResponse
    {
        $state = OnboardingState::for($request->user());

        return match ($state) {
            OnboardingState::Complete => redirect()->route('onboarding.done'),
            default => null,
        };
    }
}
