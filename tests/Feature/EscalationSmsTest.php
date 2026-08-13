<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\TwilioSmsChannel;
use App\Notifications\HandoffRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EscalationSmsTest extends TestCase
{
    use RefreshDatabase;

    private function notification(): HandoffRequestedNotification
    {
        $user = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create(['name' => 'Reception']);

        return new HandoffRequestedNotification($agent, 'embed-v1', 'visitor asked', 42, 'get me a human', 'a@b.cy');
    }

    public function test_sms_channel_joins_only_with_phone_and_twilio_config(): void
    {
        $notification = $this->notification();
        $user = User::factory()->create(['notification_phone' => '+35799123456']);

        // Phone but no Twilio config → no SMS leg.
        $this->assertNotContains(TwilioSmsChannel::class, $notification->via($user));

        config(['services.twilio.sid' => 'AC123', 'services.twilio.token' => 'tok', 'services.twilio.from' => '+15005550006']);

        $this->assertContains(TwilioSmsChannel::class, $notification->via($user));
        // Config but no phone → no SMS leg.
        $this->assertNotContains(TwilioSmsChannel::class, $notification->via(User::factory()->create()));
    }

    public function test_channel_posts_the_sms_to_twilio(): void
    {
        config(['services.twilio.sid' => 'AC123', 'services.twilio.token' => 'tok', 'services.twilio.from' => '+15005550006']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $user = User::factory()->create(['notification_phone' => '+35799123456']);
        (new TwilioSmsChannel)->send($user, $this->notification());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/Accounts/AC123/Messages.json')
                && $request['To'] === '+35799123456'
                && $request['From'] === '+15005550006'
                && str_contains($request['Body'], 'Reception')
                && str_contains($request['Body'], '/conversations/42');
        });
    }

    public function test_twilio_failure_never_throws(): void
    {
        config(['services.twilio.sid' => 'AC123', 'services.twilio.token' => 'tok', 'services.twilio.from' => '+15005550006']);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'boom'], 500)]);

        $user = User::factory()->create(['notification_phone' => '+35799123456']);
        (new TwilioSmsChannel)->send($user, $this->notification()); // no exception = pass

        $this->assertTrue(true);
    }

    public function test_profile_saves_and_validates_the_phone(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->put('/user/profile-information', [
                'name' => $user->name,
                'email' => $user->email,
                'notification_phone' => '+35799123456',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('+35799123456', $user->fresh()->notification_phone);

        // Junk gets rejected, clearing works.
        $this->actingAs($user)
            ->put('/user/profile-information', [
                'name' => $user->name,
                'email' => $user->email,
                'notification_phone' => 'not-a-phone',
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation', ['notification_phone']);

        $this->actingAs($user)
            ->put('/user/profile-information', [
                'name' => $user->name,
                'email' => $user->email,
                'notification_phone' => '',
            ])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->notification_phone);
    }
}
