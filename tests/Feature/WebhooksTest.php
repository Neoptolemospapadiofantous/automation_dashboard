<?php

namespace Tests\Feature;

use App\Billing\Plan;
use App\Jobs\DeliverWebhook;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\TeamWebhook;
use App\Models\User;
use App\Services\WebhookDispatcher;
use App\Support\PublicWebPage;
use App\Support\WebhookPayloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The URL guard resolves DNS; the suite runs offline, so stub it for
     * the public-host cases (the private-address case uses the real one —
     * it rejects before any lookup).
     */
    private function fakeGuard(): PublicWebPage
    {
        $guard = Mockery::mock(PublicWebPage::class);
        $guard->shouldReceive('assertPublicHttpUrl')->andReturn(['93.184.216.34']);
        $this->app->instance(PublicWebPage::class, $guard);

        return $guard;
    }

    private function ownerOnPaidPlan(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Starter->value])->save();
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        return $user;
    }

    public function test_free_plan_cannot_add_a_webhook(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->post(route('webhooks.store'), ['url' => 'https://example.com/hook', 'events' => ['lead.captured']])
            ->assertSessionHasErrors('url');
        $this->assertSame(0, TeamWebhook::count());
    }

    public function test_owner_adds_a_webhook_and_sees_the_secret_once(): void
    {
        $user = $this->ownerOnPaidPlan();
        $this->fakeGuard();

        $this->actingAs($user)
            ->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/abc', 'events' => ['lead.captured', 'handoff.requested']])
            ->assertRedirect()
            ->assertSessionHas('webhook_secret', fn ($s) => str_starts_with($s, 'whsec_'));

        $hook = TeamWebhook::query()->firstOrFail();
        $this->assertSame(['lead.captured', 'handoff.requested'], $hook->events);
        $this->assertArrayNotHasKey('secret', $hook->toArray());

        $this->actingAs($user)
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('webhooks', 1)->where('webhooks.0.url', 'https://hooks.example.com/abc'));
    }

    public function test_private_urls_are_refused(): void
    {
        $user = $this->ownerOnPaidPlan();

        $this->actingAs($user)
            ->post(route('webhooks.store'), ['url' => 'http://127.0.0.1/hook', 'events' => ['lead.captured']])
            ->assertSessionHasErrors('url');
    }

    public function test_a_new_chat_lead_dispatches_a_signed_delivery(): void
    {
        Queue::fake();
        $user = $this->ownerOnPaidPlan();
        $team = $user->currentTeam;
        TeamWebhook::create(['team_id' => $team->id, 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_test', 'events' => ['lead.captured'], 'active' => true]);
        TeamWebhook::create(['team_id' => $team->id, 'url' => 'https://hooks.example.com/b', 'secret' => 'whsec_test', 'events' => ['handoff.requested'], 'active' => true]);

        $lead = Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $team->current_agent_id]);
        app(WebhookDispatcher::class)->dispatch($team, 'lead.captured', WebhookPayloads::lead($lead));

        Queue::assertPushed(DeliverWebhook::class, 1);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->body['event'] === 'lead.captured'
            && $job->body['data']['id'] === $lead->id);
    }

    public function test_delivery_posts_signed_json_and_records_the_outcome(): void
    {
        $user = $this->ownerOnPaidPlan();
        $hook = TeamWebhook::create(['team_id' => $user->currentTeam->id, 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_test', 'events' => ['lead.captured'], 'active' => true]);
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

        $body = ['id' => '01X', 'event' => 'lead.captured', 'created_at' => now()->toIso8601String(), 'team_id' => 1, 'data' => ['id' => 5]];
        (new DeliverWebhook($hook->id, $body))->handle($this->fakeGuard());

        Http::assertSent(function ($request) use ($body) {
            $sig = $request->header('X-Flowstack-Signature')[0] ?? '';
            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $sig, $m);
            $expected = hash_hmac('sha256', $m[1].'.'.$request->body(), 'whsec_test');

            return $request->url() === 'https://hooks.example.com/a'
                && $request->header('X-Flowstack-Event')[0] === 'lead.captured'
                && json_decode($request->body(), true) === $body
                && $m[2] === $expected;
        });

        $hook->refresh();
        $this->assertSame(200, $hook->last_status);
        $this->assertNull($hook->last_error);
        $this->assertSame(0, $hook->failure_count);
    }

    public function test_a_failed_delivery_records_the_error_and_a_dead_endpoint_switches_off(): void
    {
        $user = $this->ownerOnPaidPlan();
        $hook = TeamWebhook::create(['team_id' => $user->currentTeam->id, 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_test', 'events' => ['lead.captured'], 'active' => true]);
        $hook->forceFill(['failure_count' => TeamWebhook::MAX_FAILURES - 1])->save();
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);

        $job = new DeliverWebhook($hook->id, ['id' => '01X', 'event' => 'lead.captured', 'data' => []]);
        // Final attempt: records, does not throw.
        $job->setJob(new class extends SyncJob
        {
            public function __construct() {}

            public function attempts(): int
            {
                return 3;
            }
        });
        $job->handle($this->fakeGuard());

        $hook->refresh();
        $this->assertSame(500, $hook->last_status);
        $this->assertSame('HTTP 500', $hook->last_error);
        $this->assertFalse($hook->active, 'switched off after MAX_FAILURES consecutive failures');
    }

    public function test_ending_a_conversation_emits_conversation_ended(): void
    {
        Queue::fake();
        $user = $this->ownerOnPaidPlan();
        $team = $user->currentTeam;
        TeamWebhook::create(['team_id' => $team->id, 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_test', 'events' => ['conversation.ended'], 'active' => true]);

        $conversation = Conversation::factory()->create(['team_id' => $team->id, 'agent_id' => $team->current_agent_id]);
        $conversation->forceFill(['status' => 'ended', 'ended_at' => now()])->save();

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->body['event'] === 'conversation.ended'
            && $job->body['data']['id'] === $conversation->id);
    }

    public function test_non_owner_cannot_manage_webhooks(): void
    {
        $owner = $this->ownerOnPaidPlan();
        $team = $owner->currentTeam;
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();
        $hook = TeamWebhook::create(['team_id' => $team->id, 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_test', 'events' => ['lead.captured'], 'active' => true]);

        $this->actingAs($member)->delete(route('webhooks.destroy', $hook->id))->assertForbidden();
        $this->assertSame(1, TeamWebhook::count());
    }
}
