<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Channels\CallMeBotTelegramCallChannel;
use App\Notifications\HandoffRequestedNotification;
use App\Notifications\LeadCapturedNotification;
use App\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_keep_every_channel_on(): void
    {
        $prefs = NotificationPreferences::fromArray(null);
        $this->assertSame(['database', 'mail'], $prefs->filter('lead_captured', ['database', 'mail']));
        $this->assertTrue($prefs->wants('weekly_digest', 'mail'));
        $this->assertFalse($prefs->isQuiet());
    }

    public function test_quiet_hours_drop_mail_and_call_but_keep_the_bell(): void
    {
        $prefs = NotificationPreferences::fromArray([
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '08:00', 'timezone' => 'Asia/Nicosia'],
        ]);
        $night = Carbon::parse('2026-09-13 23:30', 'Asia/Nicosia');
        $day = Carbon::parse('2026-09-13 11:00', 'Asia/Nicosia');

        $this->assertTrue($prefs->isQuiet($night));
        $this->assertFalse($prefs->isQuiet($day));
        $this->assertSame(['database'], $prefs->filter('handoff', ['database', 'mail', CallMeBotTelegramCallChannel::class], $night));
        $this->assertSame(['database', 'mail', CallMeBotTelegramCallChannel::class], $prefs->filter('handoff', ['database', 'mail', CallMeBotTelegramCallChannel::class], $day));
    }

    public function test_a_switched_off_channel_is_removed_from_via(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->forceFill(['notification_preferences' => ['events' => ['lead_captured' => ['mail' => false]]]])->save();
        $lead = Lead::factory()->create(['team_id' => $user->currentTeam->id]);

        $this->assertSame(['database'], (new LeadCapturedNotification($lead))->via($user->fresh()));
    }

    public function test_the_call_toggle_silences_the_phone(): void
    {
        config(['services.callmebot.telegram_user' => '+35799123456']);
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $notification = new HandoffRequestedNotification($agent, 'v1', 'asked', 1, 'hi', null);

        $this->assertContains(CallMeBotTelegramCallChannel::class, $notification->via($user));

        $user->forceFill(['notification_preferences' => ['events' => ['handoff' => ['call' => false]]]])->save();
        $this->assertSame(['database', 'mail'], $notification->via($user->fresh()));
    }

    public function test_preferences_page_saves_a_normalised_blob(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('notifications.preferences'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('preferences.events.handoff.call', true));

        $this->actingAs($user)
            ->put(route('notifications.preferences.update'), [
                'events' => ['handoff' => ['bell' => true, 'mail' => false, 'call' => true], 'made_up' => ['mail' => false]],
                'quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '07:30', 'timezone' => 'Europe/Athens'],
            ])
            ->assertRedirect();

        $prefs = $user->fresh()->notificationPreferences();
        $this->assertFalse($prefs->wants('handoff', 'mail'));
        $this->assertTrue($prefs->wants('handoff', 'call'));
        $this->assertArrayNotHasKey('made_up', $prefs->events);
        $this->assertSame('21:00', $prefs->quietHours['start']);
        $this->assertSame('Europe/Athens', $prefs->quietHours['timezone']);
    }
}
