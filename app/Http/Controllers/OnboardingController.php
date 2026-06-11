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
