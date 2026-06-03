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

        if (! $agent->isConfigured()) {
            return self::NeedsCredentials;
        }

        if ($agent->status !== Agent::STATUS_ACTIVE) {
            // Has creds but never passed health check (or was disabled).
            return self::NeedsHealthCheck;
        }

        return self::Complete;
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
