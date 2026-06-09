<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Models\VoiceflowWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEventsViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_page_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('system.webhooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('System/WebhookEvents')
                ->has('events')
                ->has('event_types')
                ->has('filter')
            );
    }

    public function test_lists_team_events_only(): void
    {
        $mine = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        $myAgent = Agent::factory()->for($mine->currentTeam)->create();
        $foreignAgent = Agent::factory()->for($other->currentTeam)->create();

        VoiceflowWebhookEvent::create([
            'agent_id' => $myAgent->id,
            'team_id' => $mine->currentTeam->id,
            'event_type' => 'runtime.session.start',
            'event_id' => 'evt_mine_1',
            'voiceflow_user_id' => 'u-1',
            'payload' => ['x' => 1],
            'signature_valid' => true,
            'received_at' => now(),
        ]);

        VoiceflowWebhookEvent::create([
            'agent_id' => $foreignAgent->id,
            'team_id' => $other->currentTeam->id,
            'event_type' => 'runtime.session.end',
            'event_id' => 'evt_other_1',
            'voiceflow_user_id' => 'u-2',
            'payload' => ['x' => 2],
            'signature_valid' => true,
            'received_at' => now(),
        ]);

        $this->actingAs($mine)
            ->get(route('system.webhooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events', 1)
                ->where('events.0.event_id', 'evt_mine_1')
            );
    }

    public function test_filters_by_state(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $processed = VoiceflowWebhookEvent::create([
            'agent_id' => $agent->id,
            'team_id' => $user->currentTeam->id,
            'event_type' => 'runtime.session.start',
            'event_id' => 'evt_processed',
            'payload' => [],
            'signature_valid' => true,
            'received_at' => now(),
            'processed_at' => now(),
        ]);
        $pending = VoiceflowWebhookEvent::create([
            'agent_id' => $agent->id,
            'team_id' => $user->currentTeam->id,
            'event_type' => 'runtime.session.start',
            'event_id' => 'evt_pending',
            'payload' => [],
            'signature_valid' => true,
            'received_at' => now(),
        ]);
        $failed = VoiceflowWebhookEvent::create([
            'agent_id' => $agent->id,
            'team_id' => $user->currentTeam->id,
            'event_type' => 'runtime.session.start',
            'event_id' => 'evt_failed',
            'payload' => [],
            'signature_valid' => true,
            'received_at' => now(),
            'processed_at' => now(),
            'processing_error' => 'boom',
        ]);

        // processed
        $this->actingAs($user)
            ->get(route('system.webhooks.index').'?state=processed')
            ->assertInertia(fn ($page) => $page->has('events', 1)
                ->where('events.0.event_id', 'evt_processed'));

        // pending
        $this->actingAs($user)
            ->get(route('system.webhooks.index').'?state=pending')
            ->assertInertia(fn ($page) => $page->has('events', 1)
                ->where('events.0.event_id', 'evt_pending'));

        // failed
        $this->actingAs($user)
            ->get(route('system.webhooks.index').'?state=failed')
            ->assertInertia(fn ($page) => $page->has('events', 1)
                ->where('events.0.event_id', 'evt_failed'));
    }

    public function test_filters_by_event_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        VoiceflowWebhookEvent::create([
            'agent_id' => $agent->id,
            'team_id' => $user->currentTeam->id,
            'event_type' => 'runtime.session.start',
            'event_id' => 'evt_a',
            'payload' => [],
            'signature_valid' => true,
            'received_at' => now(),
        ]);
        VoiceflowWebhookEvent::create([
            'agent_id' => $agent->id,
            'team_id' => $user->currentTeam->id,
            'event_type' => 'runtime.session.end',
            'event_id' => 'evt_b',
            'payload' => [],
            'signature_valid' => true,
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('system.webhooks.index').'?type=runtime.session.end')
            ->assertInertia(fn ($page) => $page->has('events', 1)
                ->where('events.0.event_type', 'runtime.session.end'));
    }
}
