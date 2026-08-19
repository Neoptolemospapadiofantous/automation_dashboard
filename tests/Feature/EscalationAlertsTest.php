<?php

namespace Tests\Feature;

use App\Jobs\PlaceEscalationCall;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\CallMeBotTelegramCallChannel;
use App\Notifications\HandoffRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EscalationAlertsTest extends TestCase
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

    public function test_call_leg_joins_when_configured_and_queues_one_call(): void
    {
        $notification = $this->notification();
        $user = User::factory()->create();

        // Unconfigured → no call leg.
        $this->assertNotContains(CallMeBotTelegramCallChannel::class, $notification->via($user));

        config(['services.callmebot.telegram_user' => '+35799123456']);
        $this->assertContains(CallMeBotTelegramCallChannel::class, $notification->via($user));

        // Queued (the visitor's turn never waits on the gateway), one call.
        Queue::fake();
        (new CallMeBotTelegramCallChannel)->send($user, $notification);
        Queue::assertPushed(PlaceEscalationCall::class, 1);
        Queue::assertPushed(PlaceEscalationCall::class, function (PlaceEscalationCall $job) {
            return str_contains($job->text, 'Reception')
                && str_contains($job->text, 'Contact details are on file')
                && mb_strlen($job->text) <= 256;
        });
    }

    public function test_call_job_dials_callmebot_with_tts_and_text_copy(): void
    {
        config(['services.callmebot.telegram_user' => '+35799123456']);
        Http::fake(['api.callmebot.com/*' => Http::response('Call queued')]);

        (new PlaceEscalationCall('Flowstack alert. A visitor on Reception is asking for a human.'))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.callmebot.com/start.php')
                && $request['user'] === '+35799123456'
                && $request['rpt'] == 3
                && $request['timeout'] == 60
                && $request['cc'] === 'yes'
                && str_contains($request['text'], 'Reception');
        });
    }

    public function test_call_job_failure_never_throws(): void
    {
        config(['services.callmebot.telegram_user' => '+35799123456']);
        Http::fake(['api.callmebot.com/*' => Http::response('boom', 500)]);

        (new PlaceEscalationCall('test'))->handle(); // no exception = pass

        $this->assertTrue(true);
    }
}
