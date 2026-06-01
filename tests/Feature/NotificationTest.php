<?php

namespace Tests\Feature;

use App\Enums\AssignmentStrategy;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadDelegator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_lead_notifies_the_rep(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $rep = User::factory()->create();
        $team->users()->attach($rep, ['role' => 'editor']);

        $lead = Lead::factory()->create(['team_id' => $team->id]);

        app(LeadDelegator::class)->assign(
            lead: $lead,
            strategy: AssignmentStrategy::Manual,
            byUser: $owner,
            toUserId: $rep->id,
        );

        $this->assertCount(1, $rep->fresh()->unreadNotifications);
        $this->assertSame($lead->id, $rep->unreadNotifications->first()->data['lead_id']);
    }

    public function test_self_assignment_does_not_notify(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->create(['team_id' => $owner->currentTeam->id]);

        app(LeadDelegator::class)->assign(
            lead: $lead,
            strategy: AssignmentStrategy::Manual,
            byUser: $owner,
            toUserId: $owner->id,
        );

        $this->assertCount(0, $owner->fresh()->unreadNotifications);
    }

    public function test_mark_all_read_endpoint_clears_notifications(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $rep = User::factory()->create();
        $team->users()->attach($rep, ['role' => 'editor']);

        $lead = Lead::factory()->create(['team_id' => $team->id]);
        app(LeadDelegator::class)->assign($lead, AssignmentStrategy::Manual, $owner, $rep->id);

        $this->assertCount(1, $rep->fresh()->unreadNotifications);

        $this->actingAs($rep)->post(route('notifications.read'))->assertRedirect();

        $this->assertCount(0, $rep->fresh()->unreadNotifications);
    }
}
