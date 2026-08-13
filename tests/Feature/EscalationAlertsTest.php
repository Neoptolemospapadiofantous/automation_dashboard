<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\CallMeBotWhatsAppChannel;
use App\Notifications\HandoffRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_whatsapp_leg_joins_when_callmebot_configured_and_sends(): void
    {
        $notification = $this->notification();
        $user = User::factory()->create();

        // Unconfigured → no WhatsApp leg.
        $this->assertNotContains(CallMeBotWhatsAppChannel::class, $notification->via($user));

        config(['services.callmebot.phone' => '+35799123456', 'services.callmebot.apikey' => '123456']);
        $this->assertContains(CallMeBotWhatsAppChannel::class, $notification->via($user));

        Http::fake(['api.callmebot.com/*' => Http::response('Message queued')]);
        (new CallMeBotWhatsAppChannel)->send($user, $notification);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.callmebot.com/whatsapp.php')
                && $request['phone'] === '+35799123456'
                && $request['apikey'] === '123456'
                && str_contains($request['text'], 'Reception')
                && str_contains($request['text'], '/conversations/42');
        });
    }
}
