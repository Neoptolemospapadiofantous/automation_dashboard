<?php

namespace App\Lifecycle;

use App\Models\Agent;
use App\Models\Team;
use App\Models\User;

/**
 * Single source of truth for "where is this user in setup."
 *
 * Anywhere we need to render a checklist, drive a redirect, or decide
 * whether a feature should be visible — call OnboardingState::for($user)
 * and switch on the result. No more scattered "if (Agent::count() === 0)".
 *
 * Order matters: each case is "the next thing blocking the user."
 */
enum OnboardingState: string
{
    case NeedsTeam = 'needs_team';
    case NeedsAgent = 'needs_agent';
    case NeedsCredentials = 'needs_credentials';
    case NeedsHealthCheck = 'needs_health_check';
    case Complete = 'complete';

    /**
     * Resolve the user's current onboarding step from DB state.
     *
     * Resolution order matters — earlier checks short-circuit.
     */
    public static function for(User $user): self
    {
        $team = $user->currentTeam;

        if (! $team instanceof Team) {
            return self::NeedsTeam;
        }

        $agent = $team->currentAgent;

        if (! $agent) {
            // Pick the team's most recent agent if any exists — being on a
            // team with agents but no current_agent_id just means we need to
            // set one. Otherwise the user must create one first.
            $agent = $team->agents()->latest()->first();

            if (! $agent) {
                return self::NeedsAgent;
            }
        }

        // Active agents are Complete — full stop. status='active' is the
        // contract for "this is ready to go," set by the state machine on
        // a passing health check (BYOK) or by CreateAgent::createManaged
        // immediately after a successful clone (managed). Checking
        // isConfigured() here was a bug: a managed agent whose
        // .env-level config drifted (e.g. VOICEFLOW_MASTER_PROJECT_ID
        // unset) would test as not-configured even when its row says
        // active, and we'd redirect-loop the user through /onboarding/connect
        // — which is the BYOK paste-keys form, useless to a managed user.
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return self::Complete;
        }

        // Managed agents are activated atomically at create time. A managed
        // agent in any non-active state means an admin/system issue (env
        // misconfig, Voiceflow API down at clone time, manually disabled),
        // not something the user can fix via the BYOK wizard. Let them
        // through to the dashboard; surface the problem there.
        if ($agent->isManaged()) {
            return self::Complete;
        }

        // BYOK agent in draft. Has creds → just needs the health check to
        // pass. No creds → needs the paste-keys form first.
        if (! $agent->isConfigured()) {
            return self::NeedsCredentials;
        }

        return self::NeedsHealthCheck;
    }

    /**
     * The route the wizard should send the user to for this step. Returns
     * null when complete (let the caller decide where "done" goes).
     */
    public function nextRoute(): ?string
    {
        return match ($this) {
            self::NeedsTeam => 'teams.create',
            self::NeedsAgent => 'onboarding.intro',
            self::NeedsCredentials => 'onboarding.connect',
            self::NeedsHealthCheck => 'onboarding.connect',
            self::Complete => null,
        };
    }

    public function isComplete(): bool
    {
        return $this === self::Complete;
    }

    /**
     * Human-readable label for checklists / progress UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::NeedsTeam => 'Create your team',
            self::NeedsAgent => 'Create your first agent',
            self::NeedsCredentials => 'Connect Voiceflow',
            self::NeedsHealthCheck => 'Verify the connection',
            self::Complete => 'Ready to chat',
        };
    }
}
