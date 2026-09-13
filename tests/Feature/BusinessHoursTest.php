<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Channels\CallMeBotTelegramCallChannel;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Support\EscalateToHuman;
use App\Support\BusinessHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_hours_mean_always_open(): void
    {
        $hours = BusinessHours::fromArray(null);
        $this->assertFalse($hours->enabled);
        $this->assertTrue($hours->isOpen(Carbon::parse('2026-09-13 03:00', 'Asia/Nicosia'))); // a Sunday, 3am
    }

    public function test_open_and_closed_follow_the_day_windows_in_the_team_timezone(): void
    {
        $hours = BusinessHours::fromArray([
            'enabled' => true,
            'timezone' => 'Asia/Nicosia',
            'days' => ['mon' => ['09:00', '18:00'], 'sat' => null, 'sun' => null],
        ]);

        $this->assertTrue($hours->isOpen(Carbon::parse('2026-09-14 10:00', 'Asia/Nicosia'))); // Monday
        $this->assertFalse($hours->isOpen(Carbon::parse('2026-09-14 18:00', 'Asia/Nicosia'))); // closing minute is closed
        $this->assertFalse($hours->isOpen(Carbon::parse('2026-09-13 10:00', 'Asia/Nicosia'))); // Sunday
        // UTC instant that is Monday 09:30 in Nicosia (UTC+3 in September).
        $this->assertTrue($hours->isOpen(Carbon::parse('2026-09-14 06:30', 'UTC')));

        $next = $hours->nextOpening(Carbon::parse('2026-09-13 10:00', 'Asia/Nicosia'));
        $this->assertSame('2026-09-14 09:00', $next?->format('Y-m-d H:i'));
        $this->assertStringContainsString('We open Monday at 09:00.', $hours->awayLine(Carbon::parse('2026-09-13 10:00', 'Asia/Nicosia')));
    }

    public function test_invalid_windows_fall_back_and_away_message_is_capped(): void
    {
        $hours = BusinessHours::fromArray([
            'enabled' => true,
            'days' => ['mon' => ['18:00', '09:00'], 'tue' => ['9:00', '17:00']],
            'away_message' => str_repeat('x', 400),
        ]);
        $this->assertNull($hours->days['mon']);
        $this->assertNull($hours->days['tue']);
        $this->assertSame(300, mb_strlen($hours->awayMessage));
    }

    public function test_out_of_hours_escalation_keeps_the_phone_quiet_and_flags_the_conversation(): void
    {
        Notification::fake();
        config(['services.callmebot.telegram_user' => '+35799123456']);
        Carbon::setTestNow(Carbon::parse('2026-09-13 03:00', 'Asia/Nicosia')); // Sunday night

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['business_hours' => ['enabled' => true, 'timezone' => 'Asia/Nicosia', 'days' => ['sun' => null]]])->save();
        $agent = Agent::factory()->for($team)->create();
        $session = RuntimeSession::create(['agent_id' => $agent->id, 'visitor_id' => 'v-1', 'flow_state' => 'discovery', 'variables' => [], 'history' => []]);
        $conversation = Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'visitor_id' => 'v-1']);

        $escalate = app(EscalateToHuman::class);
        $context = new ConversationContext($agent, $session, 'I need a person');
        $escalate->handle($context, 'visitor asked');

        $this->assertTrue($conversation->fresh()->meta['handoff_out_of_hours']);
        $this->assertNotNull($escalate->awayLine($agent));
        Notification::assertSentTo($user, HandoffRequestedNotification::class, function ($n) use ($user) {
            return $n->ring === false && ! in_array(CallMeBotTelegramCallChannel::class, $n->via($user), true);
        });

        Carbon::setTestNow();
    }

    public function test_owner_saves_hours_and_the_page_reports_state(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->put(route('hours.update'), [
                'enabled' => true,
                'timezone' => 'Asia/Nicosia',
                'days' => ['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => null, 'thu' => null, 'fri' => null, 'sat' => null, 'sun' => null],
                'away_message' => 'Closed — back Monday.',
            ])
            ->assertRedirect();

        $hours = $user->currentTeam->fresh()->businessHours();
        $this->assertTrue($hours->enabled);
        $this->assertSame(['09:00', '17:00'], $hours->days['mon']);
        $this->assertNull($hours->days['wed']);
        $this->assertSame('Closed — back Monday.', $hours->awayMessage);

        $this->actingAs($user)
            ->put(route('hours.update'), ['enabled' => true, 'timezone' => 'Asia/Nicosia', 'days' => ['mon' => ['17:00', '09:00']]])
            ->assertSessionHasErrors('days');

        $this->actingAs($user)->get(route('hours.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('hours.enabled', true)->has('openNow'));
    }
}
