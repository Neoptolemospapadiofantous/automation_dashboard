<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RuntimeUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_for_team_owner(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Analytics')
                ->where('agent.slug', $agent->slug)
                ->where('window.days', 30)
                ->has('totals')
                ->has('series.conversations')
                ->has('series.messages')
                ->has('series.leads')
                ->has('series.credits')
                ->has('funnel')
                ->has('sources')
                ->has('hourly', 24)
            );
    }

    public function test_403_for_foreign_agent(): void
    {
        $foreigner = User::factory()->withPersonalTeam()->create();
        $foreignAgent = Agent::factory()->for($foreigner->currentTeam)->create();

        $me = User::factory()->withPersonalTeam()->create();

        $this->actingAs($me)
            ->get(route('agents.analytics', $foreignAgent->slug))
            ->assertForbidden();
    }

    public function test_window_param_clamped_to_7_30_90(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        // Valid windows pass through.
        foreach ([7, 30, 90] as $days) {
            $this->actingAs($user)
                ->get(route('agents.analytics', $agent->slug).'?window='.$days)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('window.days', $days));
        }

        // Invalid windows default to 30.
        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug).'?window=12345')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('window.days', 30));
    }

    public function test_totals_reflect_real_data(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        // 3 conversations, 5 messages, 4 leads (1 won, 2 qualified, 1 new).
        Conversation::factory()->count(3)->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
        ]);
        Message::factory()->count(5)->create([
            'conversation_id' => Conversation::factory()->for($team)->create(['agent_id' => $agent->id])->id,
            'agent_id' => $agent->id,
            'team_id' => $team->id,
            'sent_at' => now()->subDay(),
        ]);
        Lead::factory()->for($team)->create(['agent_id' => $agent->id, 'status' => LeadStatus::Won->value]);
        Lead::factory()->count(2)->for($team)->create(['agent_id' => $agent->id, 'status' => LeadStatus::Qualified->value]);
        Lead::factory()->for($team)->create(['agent_id' => $agent->id, 'status' => LeadStatus::New->value]);

        // Some credit consumption (no factory; create directly).
        CreditTransaction::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'amount' => -50,
            'reason' => CreditTransaction::REASON_CONSUME_MESSAGE,
            'meta' => [],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.conversations', 4) // 3 + the 1 created for messages factory
                ->where('totals.messages', 5)
                ->where('totals.leads', 4)
                ->where('totals.qualified', 3) // 2 qualified + 1 won
                ->where('totals.won', 1)
                ->where('totals.credits_spent', 50)
            );
    }

    public function test_capture_rate_counts_only_chat_originated_leads_and_caps_at_100(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        Conversation::factory()->count(4)->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
        ]);
        // 1 chat capture + 1 partial harvest count; 10 manual/imported leads do not.
        Lead::factory()->for($team)->create(['agent_id' => $agent->id, 'source' => 'chat']);
        Lead::factory()->for($team)->create(['agent_id' => $agent->id, 'source' => 'chat-partial']);
        Lead::factory()->count(6)->for($team)->create(['agent_id' => $agent->id, 'source' => 'manual']);
        Lead::factory()->count(4)->for($team)->create(['agent_id' => $agent->id, 'source' => 'import']);

        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.leads', 12)
                ->where('totals.capture_rate', 50) // 2 of 4, not 12 of 4
            );

        // More chat leads than conversations (partials + captures) never exceeds 100.
        Lead::factory()->count(5)->for($team)->create(['agent_id' => $agent->id, 'source' => 'chat']);

        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug))
            ->assertInertia(fn ($page) => $page->where('totals.capture_rate', 100));
    }

    public function test_quality_totals_escalation_csat_deflection_kb_hit(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();

        // 4 conversations: 1 escalated; 3 rated (2 good, 1 bad).
        Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
            'meta' => ['handoff_requested' => true],
        ]);
        Conversation::factory()->count(2)->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
            'rating' => 'good',
            'rated_at' => now()->subDay(),
        ]);
        Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
            'rating' => 'bad',
            'rated_at' => now()->subDay(),
        ]);

        // Runtime counters: 8 LLM turns (6 KB-grounded) + 2 canned deflections.
        RuntimeUsage::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'date' => now()->startOfDay(),
            'tier' => 'haiku',
            'turns' => 8,
            'tokens_in' => 100,
            'tokens_out' => 100,
            'canned_turns' => 2,
            'kb_hits' => 6,
            'low_confidence_turns' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('agents.analytics', $agent->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.escalated', 1)
                ->where('totals.escalation_rate', 25)     // 1 of 4 (JSON drops the .0)
                ->where('totals.rated', 3)
                ->where('totals.csat', 66.7)              // 2 good of 3 rated
                ->where('totals.canned_turns', 2)
                ->where('totals.deflection_rate', 20)     // 2 of (2+8)
                ->where('totals.llm_turns', 8)
                ->where('totals.kb_hit_rate', 75)         // 6 of 8
            );
    }

    public function test_funnel_contains_every_status_in_board_order(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->get(route('agents.analytics', $agent->slug));

        $response->assertInertia(fn ($page) => $page
            ->has('funnel', count(LeadStatus::board()))
        );
    }
}
