<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadCapturedNotification;
use App\Notifications\LeadFollowUpNudge;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\CaptureLeadTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The speed-to-lead loop: capture alerts the owner immediately, "Mark
 * contacted" stamps the follow-up clock, and the daily nudge command
 * chases open leads nobody contacted — exactly once each.
 */
class LeadFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_capture_notifies_the_owner_once(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create(['runtime_mode' => 'native']);
        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'v-cap',
            'flow_state' => 'discovery',
            'variables' => [],
            'history' => [],
        ]);
        $context = new ConversationContext($agent, $session, 'sure, jane@acme.com');

        app(CaptureLeadTool::class)->execute(['name' => 'Jane', 'email' => 'jane@acme.com'], $context);

        Notification::assertSentTo($owner, LeadCapturedNotification::class);

        // Same visitor correcting details → update, not a fresh alert.
        app(CaptureLeadTool::class)->execute(['name' => 'Jane Doe', 'email' => 'jane@acme.com'], $context);
        Notification::assertSentToTimes($owner, LeadCapturedNotification::class, 1);
    }

    public function test_mark_contacted_stamps_the_lead(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->for($user->currentTeam)->create(['last_contacted_at' => null]);

        $this->actingAs($user)
            ->patch(route('leads.contacted', $lead->id))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($lead->fresh()->last_contacted_at);
    }

    public function test_mark_contacted_is_team_scoped(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $owner = User::factory()->withPersonalTeam()->create();
        $lead = Lead::factory()->for($owner->currentTeam)->create();

        $this->actingAs($stranger)
            ->patch(route('leads.contacted', $lead->id))
            ->assertForbidden();
    }

    public function test_nudge_command_chases_stale_leads_once(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $stale = Lead::factory()->for($team)->create([
            'status' => 'new',
            'last_contacted_at' => null,
            'created_at' => now()->subDays(3),
        ]);
        // Contacted → no nudge. Fresh → not yet. Won → out of scope.
        Lead::factory()->for($team)->create(['status' => 'new', 'last_contacted_at' => now()->subDay(), 'created_at' => now()->subDays(3)]);
        Lead::factory()->for($team)->create(['status' => 'new', 'last_contacted_at' => null, 'created_at' => now()->subHours(2)]);
        Lead::factory()->for($team)->create(['status' => 'won', 'last_contacted_at' => null, 'created_at' => now()->subDays(3)]);

        $this->artisan('leads:follow-up-nudges')->assertSuccessful();

        Notification::assertSentToTimes($owner, LeadFollowUpNudge::class, 1);
        $this->assertNotNull($stale->fresh()->follow_up_nudged_at);

        // Second run: already nudged — silence.
        $this->artisan('leads:follow-up-nudges')->assertSuccessful();
        Notification::assertSentToTimes($owner, LeadFollowUpNudge::class, 1);
    }

    public function test_nudge_goes_to_assignee_when_assigned(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $rep = User::factory()->create();
        $owner->currentTeam->users()->attach($rep, ['role' => 'editor']);

        Lead::factory()->for($owner->currentTeam)->create([
            'status' => 'assigned',
            'assigned_to' => $rep->id,
            'last_contacted_at' => null,
            'created_at' => now()->subDays(3),
        ]);

        $this->artisan('leads:follow-up-nudges')->assertSuccessful();

        Notification::assertSentTo($rep, LeadFollowUpNudge::class);
        Notification::assertNotSentTo($owner, LeadFollowUpNudge::class);
    }
}
