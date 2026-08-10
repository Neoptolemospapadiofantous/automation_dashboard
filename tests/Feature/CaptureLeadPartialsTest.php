<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureLeadPartialsTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $user->currentTeam;
        $this->agent = Agent::factory()->for($team)->create();
    }

    private function quietConversation(string $visitorId, int $messages = 4): Conversation
    {
        return Conversation::factory()->create([
            'team_id' => $this->agent->team_id,
            'agent_id' => $this->agent->id,
            'visitor_id' => $visitorId,
            'channel' => 'embed',
            'message_count' => $messages,
            'last_message_at' => now()->subHours(2),
        ]);
    }

    private function sessionWithVars(string $visitorId, array $vars): RuntimeSession
    {
        return RuntimeSession::create([
            'agent_id' => $this->agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => 'discovery',
            'variables' => $vars,
            'history' => [],
            'last_activity_at' => now()->subHours(2),
        ]);
    }

    public function test_harvests_partial_lead_from_ended_chat_with_identity_but_no_contact(): void
    {
        $conv = $this->quietConversation('embed-partial-1');
        $this->sessionWithVars('embed-partial-1', [
            'name' => 'Jordan',
            'company' => 'Bakery in Larnaca',
            'need' => 'Phone bookings in Greek on weekends',
        ]);

        $this->artisan('leads:capture-partials')->assertSuccessful();

        $lead = Lead::firstOrFail();
        $this->assertSame('Jordan', $lead->name);
        $this->assertSame('Bakery in Larnaca', $lead->company);
        $this->assertNull($lead->email);
        $this->assertSame('chat-partial', $lead->source);
        $this->assertSame(20, $lead->score);
        $this->assertStringContainsString('Phone bookings in Greek', (string) $lead->notes);
        $this->assertStringContainsString('partial', (string) $lead->notes);
        // Conversation is linked so the transcript view shows who it was.
        $this->assertSame($lead->id, $conv->fresh()->lead_id);
    }

    public function test_skips_greeting_only_and_anonymous_and_already_captured_chats(): void
    {
        // Greeting-only: too few messages.
        $this->quietConversation('embed-greeting', messages: 1);
        $this->sessionWithVars('embed-greeting', ['name' => 'Ghost']);

        // Engaged but nothing identifying in the session variables.
        $this->quietConversation('embed-anon');
        $this->sessionWithVars('embed-anon', ['topic' => 'pricing']);

        // Already produced a real lead.
        $lead = Lead::factory()->create(['team_id' => $this->agent->team_id, 'agent_id' => $this->agent->id]);
        $this->quietConversation('embed-captured')->update(['lead_id' => $lead->id]);
        $this->sessionWithVars('embed-captured', ['name' => 'Already Captured']);

        $this->artisan('leads:capture-partials')->assertSuccessful();

        $this->assertSame(1, Lead::count()); // only the pre-existing real lead
    }

    public function test_rerun_updates_the_same_partial_instead_of_duplicating(): void
    {
        $this->quietConversation('embed-idem');
        $this->sessionWithVars('embed-idem', ['name' => 'Maria', 'company' => 'Clinic']);

        $this->artisan('leads:capture-partials')->assertSuccessful();
        $this->artisan('leads:capture-partials')->assertSuccessful();

        $this->assertSame(1, Lead::count());
    }

    public function test_fresh_conversations_are_left_alone_until_quiet(): void
    {
        // Visitor may still be typing — last message 5 minutes ago.
        Conversation::factory()->create([
            'team_id' => $this->agent->team_id,
            'agent_id' => $this->agent->id,
            'visitor_id' => 'embed-live',
            'channel' => 'embed',
            'message_count' => 4,
            'last_message_at' => now()->subMinutes(5),
        ]);
        $this->sessionWithVars('embed-live', ['name' => 'Still Typing']);

        $this->artisan('leads:capture-partials')->assertSuccessful();

        $this->assertSame(0, Lead::count());
    }
}
