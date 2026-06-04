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
 * Phase 14 collapsed the state machine: NeedsCredentials and
 * NeedsHealthCheck were BYOK-only and unreachable now that the wizard
 * is one-step managed. The three remaining states cover everything:
 * no team, team-but-no-agent, or done.
 *
 * Order matters: each case is "the next thing blocking the user."
 */
enum OnboardingState: string
{
    case NeedsTeam = 'needs_team';
    case NeedsAgent = 'needs_agent';
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

        $agent = $team->currentAgent
            ?? $team->agents()->latest()->first();

        if (! $agent) {
            return self::NeedsAgent;
        }

        // Managed signups land ACTIVE atomically (pool allocate + stamp
        // credentials + status='active' in one CreateAgent call). Anything
        // less than active = ops/provisioning issue, not something the
        // user can resolve from a wizard — let them through to the
        // dashboard, surface the problem there.
        //
        // Dormant BYOK agents (ops-wired for Custom-tier customers) are
        // also treated as Complete: the user can't paste credentials via
        // the UI anymore, so there's no productive onboarding redirect.
        return self::Complete;
    }

    /**
     * The route the wizard should send the user to for this step.
     * Returns null when complete (caller picks where "done" goes).
     */
    public function nextRoute(): ?string
    {
        return match ($this) {
            self::NeedsTeam => 'teams.create',
            self::NeedsAgent => 'onboarding.intro',
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
            self::NeedsAgent => 'Set up your agent',
            self::Complete => 'Ready to chat',
        };
    }
}
