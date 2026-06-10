<?php

namespace App\Authorization;

use App\Models\Team;
use App\Models\User;

/**
 * Per-team role for a user, with capability checks. Sits on top of Jetstream's
 * built-in admin/editor roles (defined in JetstreamServiceProvider) and adds:
 *
 *   - Owner: $team->user_id === $user->id (Jetstream stores the owner FK
 *     in user_id, not owner_id; $team->owner is the relation accessor)
 *   - Admin: invited user with Jetstream role 'admin'
 *   - Editor: invited user with Jetstream role 'editor'
 *   - Member: anyone else who belongs to the team (no Jetstream role set)
 *
 * The capability matrix lives here as can*() methods. Policies delegate to
 * this enum so the matrix stays in one place; controllers / Blade /
 * Inertia all share the same source of truth.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Member = 'member';

    public static function forUser(User $user, Team $team): self
    {
        if ((int) $team->user_id === (int) $user->id) {
            return self::Owner;
        }

        $jetstreamRole = $user->teamRole($team);
        if ($jetstreamRole === null) {
            return self::Member;
        }

        return match ($jetstreamRole->key) {
            'admin' => self::Admin,
            'editor' => self::Editor,
            default => self::Member,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Editor => 'Editor',
            self::Member => 'Member',
        };
    }

    // ── Capabilities ────────────────────────────────────────────────────────
    //
    // Owner-only: anything that affects money, identity, or kills the agent.
    // Admin or higher: anything that destroys data without billing impact.
    // Editor or higher: anything that creates / modifies content.
    // Member: read-only + chat.

    /** Delete the agent, rotate webhook secret. */
    public function canDeleteAgent(): bool
    {
        return $this === self::Owner;
    }

    /** Rename agent, switch current agent. */
    public function canUpdateAgent(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Create a new agent (subject to plan limit). */
    public function canCreateAgent(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Delete leads from the kanban (mass-destructive). */
    public function canDeleteLead(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Delete KB documents. */
    public function canDeleteKnowledge(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Add KB documents (URL, file, text). */
    public function canAddKnowledge(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }

    /** Create / update leads. */
    public function canManageLeads(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }
}
