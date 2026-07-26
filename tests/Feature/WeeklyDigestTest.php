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

    public function test_digest_names_the_team_and_neutralizes_markdown_in_gaps(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->forceFill(['name' => 'Acme Rentals'])->save();
        $agent = Agent::factory()->for($team)->create(['status' => 'active']);

        Conversation::factory()->for($team)->create([
            'agent_id' => $agent->id,
            'started_at' => now()->subDays(2),
        ]);
        KbGap::record($agent->id, '[pay here](https://phish.example)', 0.1);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($owner, WeeklyDigestEmail::class, function (WeeklyDigestEmail $n) use ($owner): bool {
            $mail = $n->toMail($owner);
            $subjectNamesTeam = str_contains($mail->subject, 'Acme Rentals');
            // The gap question keeps its literal text but its markdown link
            // syntax is escaped so it can't render as a live link.
            $gapLine = collect($mail->introLines)->first(fn (string $l): bool => str_contains($l, 'pay here'));

            return $subjectNamesTeam
                && $gapLine !== null
                && str_contains($gapLine, '\\[pay here\\]')
                && ! str_contains($gapLine, '](https');
        });
    }

    public function test_per_agent_lines_include_disabled_agent_traffic(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $active = Agent::factory()->for($team)->create(['status' => 'active', 'name' => 'Active bot']);
        $disabled = Agent::factory()->for($team)->create(['status' => 'disabled', 'name' => 'Old bot']);

        Conversation::factory()->for($team)->create(['agent_id' => $active->id, 'started_at' => now()->subDay()]);
        Conversation::factory()->count(2)->for($team)->create(['agent_id' => $disabled->id, 'started_at' => now()->subDay()]);

        $this->artisan('teams:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($owner, WeeklyDigestEmail::class, function (WeeklyDigestEmail $n): bool {
            $byName = collect($n->stats['agents'])->keyBy('name');

            // Headline counts all 3; both agents appear so the lines sum to it.
            return $n->stats['conversations'] === 3
                && count($n->stats['agents']) === 2
                && $byName['Active bot']['conversations'] === 1
                && $byName['Old bot']['conversations'] === 2;
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
