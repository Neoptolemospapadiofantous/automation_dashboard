<?php

namespace App\Http\Controllers;

use App\Actions\Agents\CreateAgent;
use App\Lifecycle\OnboardingState;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The (now single-step) onboarding wizard.
 *
 * Phase 14 collapsed the wizard from 3 steps (intro → connect → done)
 * to 2 (intro → done) when BYOK was removed from the product surface.
 * The wizard is now always managed: user clicks "Set up my agent",
 * CreateAgent allocates from the Voiceflow project pool, marks the
 * agent active, redirects to Done.
 *
 * The dormant pieces (credentialRules, credentialMessages,
 * UpdateAgentCredentials action) stay because ops uses them via
 * tinker for one-off Custom-tier BYOK setups. They're not reachable
 * via any user-facing route — the Connect + saveCredentials methods
 * were deleted in Phase 14.
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

        return Inertia::render('Onboarding/Intro', [
            'team' => ['id' => $request->user()->currentTeam->id, 'name' => $request->user()->currentTeam->name],
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
        $existing ?: (new CreateAgent)->execute($team, $data['name'] ?? 'Default agent');

        // Managed signups are activated atomically by CreateAgent — no
        // credential-paste step. Go straight to Done.
        return redirect()->route('onboarding.done');
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
     * Voiceflow credential validation, shared with AgentController so the
     * (now ops-only) BYOK path enforces identical formats. Strict regexes
     * catch the "I pasted my email by accident" class of error before we
     * even round-trip Voiceflow.
     *
     * Kept public + static even though the only call site is the dormant
     * BYOK update path — ops calls it from tinker.
     *
     * @return array<string, array<int, string>>
     */
    public static function credentialRules(bool $required): array
    {
        $base = $required ? ['required'] : ['nullable'];

        return [
            'voiceflow_api_key' => array_merge($base, ['string', 'max:255', 'regex:/^VF\.DM\.[A-Za-z0-9]+\.[A-Za-z0-9]+$/']),
            'voiceflow_project_id' => array_merge($base, ['string', 'max:255', 'regex:/^[a-f0-9]{24}$/i']),
            'voiceflow_environment' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]{2,32}$/'],
            'voiceflow_workspace_api_key' => ['nullable', 'string', 'max:255', 'regex:/^VF\..+/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function credentialMessages(): array
    {
        return [
            'voiceflow_api_key.regex' => 'API key should look like VF.DM.xxxxxxxx.yyyy (Voiceflow Dialog Manager key).',
            'voiceflow_project_id.regex' => 'Project ID should be a 24-character hex string from your Voiceflow URL (e.g. 64f8a1b2c3d4e5f6a7b8c9d0).',
            'voiceflow_environment.regex' => 'Environment should be a short alphanumeric label (e.g. main, development).',
            'voiceflow_workspace_api_key.regex' => 'Workspace key should start with VF. (it is the broader workspace token, not the DM key).',
        ];
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
