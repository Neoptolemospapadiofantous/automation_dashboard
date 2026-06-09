<?php

namespace Tests\Feature;

use App\Events\Domain\AgentHealthChecked;
use App\Models\Agent;
use App\Models\User;
use App\Notifications\AgentUnhealthyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AgentUnhealthyNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_owner_when_health_transitions_to_failed(): void
    {
        Notification::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['last_health_ok' => false]);

        event(new AgentHealthChecked(
            agent: $agent,
            ok: false,
            result: ['reason' => 'Voiceflow returned 401'],
            wasOk: true, // transition: was healthy, now isn't
        ));

        Notification::assertSentTo($user, AgentUnhealthyNotification::class);
    }

    public function test_does_not_notify_if_already_unhealthy(): void
    {
        Notification::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['last_health_ok' => false]);

        event(new AgentHealthChecked(
            agent: $agent,
            ok: false,
            result: ['reason' => 'Voiceflow still down'],
            wasOk: false, // already broken before this probe
        ));

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_on_successful_probe(): void
    {
        Notification::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['last_health_ok' => true]);

        event(new AgentHealthChecked(
            agent: $agent,
            ok: true,
            result: ['ok' => true],
            wasOk: true,
        ));

        Notification::assertNothingSent();
    }

    public function test_notifies_on_first_probe_failure_when_unknown(): void
    {
        Notification::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['last_health_ok' => false]);

        event(new AgentHealthChecked(
            agent: $agent,
            ok: false,
            result: ['reason' => 'first probe'],
            wasOk: null, // first probe — operator hasn't been told yet
        ));

        Notification::assertSentTo($user, AgentUnhealthyNotification::class);
    }
}
