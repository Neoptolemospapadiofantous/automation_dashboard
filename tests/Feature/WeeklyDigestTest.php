<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\WeeklyDigestEmail;
use App\Runtime\Models\KbGap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_reaches_the_owner_with_correct_numbers(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->forceFill(['credit_balance' => 900, 'topup_balance' => 100])->save();
        $agent = Agent::factory()->for($team)->create(['status' => 'active']);

        Conversation::factory()->count(2)->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
        ]);
        Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(3),
            'meta' => ['handoff_requested' => true],
            'rating' => 'good',
            'rated_at' => now()->subDay(),
        ]);
        Lead::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'status' => 'qualified',
            'last_contacted_at' => null,
            'created_at' => now()->subDays(2),
        ]);
        KbGap::record($agent->id, 'Do you support SSO?', 0.2);
        KbGap::record($agent->id, 'Do you support SSO?', 0.2);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($owner, WeeklyDigestEmail::class, function (WeeklyDigestEmail $n): bool {
            return $n->stats['conversations'] === 3
                && $n->stats['leads'] === 1
                && $n->stats['qualified'] === 1
                && $n->stats['escalated'] === 1
                && $n->stats['csat'] === 100.0
                && $n->stats['stale_leads'] === 1
                && $n->stats['credits_remaining'] === 1000
                && $n->stats['gaps'][0]['question'] === 'Do you support SSO?'
                && $n->stats['gaps'][0]['asked_count'] === 2
                && $n->stats['agents'] === []; // single agent → no per-agent lines
        });
    }

    public function test_quiet_week_sends_nothing(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($owner->currentTeam)->create(['status' => 'active']);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertNotSentTo($owner, WeeklyDigestEmail::class);
    }

    public function test_old_activity_outside_window_does_not_trigger(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create(['status' => 'active']);
        Conversation::factory()->for($owner->currentTeam)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(30),
        ]);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertNotSentTo($owner, WeeklyDigestEmail::class);
    }

    public function test_team_without_active_agent_is_skipped(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create(['status' => 'disabled']);
        Conversation::factory()->for($owner->currentTeam)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDay(),
        ]);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertNotSentTo($owner, WeeklyDigestEmail::class);
    }

    public function test_multi_agent_team_gets_per_agent_lines(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $a = Agent::factory()->for($team)->create(['status' => 'active', 'name' => 'Sales bot']);
        $b = Agent::factory()->for($team)->create(['status' => 'active', 'name' => 'Support bot']);

        Conversation::factory()->count(2)->for($team)->create(['agent_id' => $a->id, 'started_at' => now()->subDay()]);
        Conversation::factory()->for($team)->create(['agent_id' => $b->id, 'started_at' => now()->subDay()]);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($owner, WeeklyDigestEmail::class, function (WeeklyDigestEmail $n): bool {
            $byName = collect($n->stats['agents'])->keyBy('name');

            return count($n->stats['agents']) === 2
                && $byName['Sales bot']['conversations'] === 2
                && $byName['Support bot']['conversations'] === 1;
        });
    }
}
