<?php

namespace Tests\Feature;

use App\Jobs\SendEscalationWhatsApp;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\CallMeBotWhatsAppChannel;
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

    public function test_whatsapp_leg_joins_when_callmebot_configured_and_bursts(): void
    {
        $notification = $this->notification();
        $user = User::factory()->create();

        // Unconfigured → no WhatsApp leg.
        $this->assertNotContains(CallMeBotWhatsAppChannel::class, $notification->via($user));

        config(['services.callmebot.phone' => '+35799123456', 'services.callmebot.apikey' => '123456']);
        $this->assertContains(CallMeBotWhatsAppChannel::class, $notification->via($user));

        // The ring burst: three queued sends (0/45/90s) so the visitor's
        // turn never waits on the gateway and the phone rings like a call.
        Queue::fake();
        (new CallMeBotWhatsAppChannel)->send($user, $notification);
        Queue::assertPushed(SendEscalationWhatsApp::class, 3);
        Queue::assertPushed(SendEscalationWhatsApp::class, fn ($job) => str_contains($job->text, 'Reception') && ! str_contains($job->text, 'reminder'));
        Queue::assertPushed(SendEscalationWhatsApp::class, fn ($job) => str_contains($job->text, 'reminder 3/3'));
    }

    public function test_whatsapp_job_posts_to_callmebot(): void
    {
        config(['services.callmebot.phone' => '+35799123456', 'services.callmebot.apikey' => '123456']);
        Http::fake(['api.callmebot.com/*' => Http::response('Message queued')]);

        (new SendEscalationWhatsApp('🚨 Visitor needs a HUMAN — Reception'))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.callmebot.com/whatsapp.php')
                && $request['phone'] === '+35799123456'
                && $request['apikey'] === '123456'
                && str_contains($request['text'], 'Reception');
        });
    }

    public function test_whatsapp_job_failure_never_throws(): void
    {
        config(['services.callmebot.phone' => '+35799123456', 'services.callmebot.apikey' => '123456']);
        Http::fake(['api.callmebot.com/*' => Http::response('boom', 500)]);

        (new SendEscalationWhatsApp('test'))->handle(); // no exception = pass

        $this->assertTrue(true);
    }
}
