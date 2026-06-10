<?php

namespace Tests\Feature;

use App\Authorization\Role;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-team role-based access control. Covers the capability matrix in
 * App\Authorization\Role and that the wired controllers enforce it.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_role_resolves_for_team_owner(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->assertSame(Role::Owner, Role::forUser($owner, $owner->currentTeam));
    }

    public function test_admin_role_resolves_for_invited_admin(): void
    {
        [$owner, $team] = $this->teamWithOwner();
        $admin = $this->joinTeam($team, 'admin');

        $this->assertSame(Role::Admin, Role::forUser($admin, $team));
    }

    public function test_editor_role_resolves_for_invited_editor(): void
    {
        [, $team] = $this->teamWithOwner();
        $editor = $this->joinTeam($team, 'editor');

        $this->assertSame(Role::Editor, Role::forUser($editor, $team));
    }

    public function test_member_role_for_unsigned_membership(): void
    {
        [, $team] = $this->teamWithOwner();
        // Attach without a role string → falls through to Member.
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => null]);

        $this->assertSame(Role::Member, Role::forUser($member, $team));
    }

    public function test_capability_matrix(): void
    {
        $this->assertTrue(Role::Owner->canDeleteAgent());
        $this->assertFalse(Role::Admin->canDeleteAgent());

        $this->assertTrue(Role::Owner->canUpdateAgent());
        $this->assertTrue(Role::Admin->canUpdateAgent());
        $this->assertFalse(Role::Editor->canUpdateAgent());

        $this->assertTrue(Role::Editor->canAddKnowledge());
        $this->assertFalse(Role::Member->canAddKnowledge());

        $this->assertTrue(Role::Admin->canDeleteKnowledge());
        $this->assertFalse(Role::Editor->canDeleteKnowledge());

        $this->assertTrue(Role::Owner->canDeleteLead());
        $this->assertTrue(Role::Admin->canDeleteLead());
        $this->assertFalse(Role::Editor->canDeleteLead());

        $this->assertTrue(Role::Editor->canManageLeads());
        $this->assertFalse(Role::Member->canManageLeads());
    }

    public function test_editor_cannot_delete_agent(): void
    {
        [$owner, $team] = $this->teamWithOwner();
        $agent = Agent::factory()->for($team)->create();
        $editor = $this->joinTeam($team, 'editor');
        // Editor switches to that team so currentTeam resolves correctly.
        $editor->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($editor->fresh())
            ->delete(route('agents.destroy', $agent->slug))
            ->assertForbidden();

        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
    }

    public function test_owner_can_delete_agent(): void
    {
        [$owner, $team] = $this->teamWithOwner();
        $agent = Agent::factory()->for($team)->create();

        $this->actingAs($owner)
            ->delete(route('agents.destroy', $agent->slug))
            ->assertRedirect(route('agents.index'));

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }

    public function test_editor_cannot_topup(): void
    {
        [, $team] = $this->teamWithOwner();
        $editor = $this->joinTeam($team, 'editor');
        $editor->forceFill(['current_team_id' => $team->id])->save();
        $team->forceFill(['credit_balance' => 100])->save();

        $this->actingAs($editor->fresh())
            ->post(route('billing.topup'), ['pack' => 'small'])
            ->assertForbidden();
    }

    public function test_admin_can_update_agent_name(): void
    {
        [, $team] = $this->teamWithOwner();
        $admin = $this->joinTeam($team, 'admin');
        $admin->forceFill(['current_team_id' => $team->id])->save();
        $agent = Agent::factory()->for($team)->create(['name' => 'Old name']);

        $this->actingAs($admin->fresh())
            ->put(route('agents.update', $agent->slug), ['name' => 'New name'])
            ->assertRedirect();

        $this->assertSame('New name', $agent->fresh()->name);
    }

    public function test_editor_cannot_rotate_webhook_secret(): void
    {
        [, $team] = $this->teamWithOwner();
        $agent = Agent::factory()->for($team)->create();
        $editor = $this->joinTeam($team, 'editor');
        $editor->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($editor->fresh())
            ->post(route('agents.rotate-secret', $agent->slug))
            ->assertForbidden();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Team} */
    private function teamWithOwner(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();

        return [$owner, $owner->currentTeam];
    }

    private function joinTeam(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);

        return $user;
    }
}
