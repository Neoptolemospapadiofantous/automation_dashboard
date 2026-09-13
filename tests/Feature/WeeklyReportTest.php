<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\WeeklyDigestEmail;
use App\Support\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private function teamWithActivity(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create(['status' => 'active']);
        $team->forceFill(['current_agent_id' => $agent->id])->save();
        Conversation::factory()->count(3)->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'started_at' => now()->subDays(2)]);
        Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'created_at' => now()->subDays(2), 'status' => 'won']);

        return $user;
    }

    public function test_stats_cover_the_window_and_carry_the_share_url_when_enabled(): void
    {
        $user = $this->teamWithActivity();
        $team = $user->currentTeam;

        $stats = app(WeeklyReport::class)->stats($team);
        $this->assertSame(3, $stats['conversations']);
        $this->assertSame(1, $stats['leads']);
        $this->assertSame(1, $stats['won']);
        $this->assertCount(7, $stats['daily']);
        $this->assertNull($stats['share_url']);

        $team->forceFill(['report_token' => WeeklyReport::newToken()])->save();
        $this->assertStringContainsString('/report/'.$team->report_token, app(WeeklyReport::class)->stats($team->fresh())['share_url']);
    }

    public function test_public_page_renders_by_token_and_404s_otherwise(): void
    {
        $user = $this->teamWithActivity();
        $team = $user->currentTeam;
        $team->forceFill(['report_token' => WeeklyReport::newToken()])->save();

        $this->get(route('report.public', $team->report_token))
            ->assertOk()
            ->assertSee($team->name)
            ->assertSee('Conversations by day')
            ->assertHeader('content-type', 'text/html; charset=UTF-8');

        $this->get('/report/'.str_repeat('a', 40))->assertNotFound();

        $team->forceFill(['report_token' => null])->save();
        $this->get('/report/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_owner_enables_rotates_and_disables_sharing(): void
    {
        $user = $this->teamWithActivity();
        $team = $user->currentTeam;

        $this->actingAs($user)->post(route('report.enable'))->assertRedirect();
        $first = $team->fresh()->report_token;
        $this->assertNotNull($first);

        $this->actingAs($user)->post(route('report.rotate'))->assertRedirect();
        $second = $team->fresh()->report_token;
        $this->assertNotSame($first, $second);
        $this->get(route('report.public', $first))->assertNotFound();
        $this->get(route('report.public', $second))->assertOk();

        $this->actingAs($user)->post(route('report.disable'))->assertRedirect();
        $this->assertNull($team->fresh()->report_token);

        $this->actingAs($user)->get(route('report.settings'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('shareUrl', null)->where('preview.conversations', 3));
    }

    public function test_digest_carries_the_share_link_and_honours_the_preference(): void
    {
        Notification::fake();
        $user = $this->teamWithActivity();
        $team = $user->currentTeam;
        $team->forceFill(['report_token' => WeeklyReport::newToken()])->save();

        $this->artisan('teams:weekly-digest')->assertSuccessful();
        Notification::assertSentTo($user, WeeklyDigestEmail::class, fn (WeeklyDigestEmail $n) => str_contains((string) $n->stats['share_url'], $team->report_token));

        Notification::fake();
        $user->forceFill(['notification_preferences' => ['events' => ['weekly_digest' => ['mail' => false]]]])->save();
        $this->artisan('teams:weekly-digest')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
