<?php

namespace Tests\Feature\Runtime;

use App\Events\LeadMessage;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Support\EscalateToHuman;
use App\Runtime\Tools\RequestHandoffTool;
use App\Services\ConversationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The never-miss-a-lead pipeline: escalation marks the conversation and
 * notifies the owner with actionable context; the owner replies live from
 * the dashboard (takeover — the AI stands down, no LLM, no credits); the
 * widget polls team replies; release hands the chat back.
 */
class HumanTakeoverTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Agent, 2: Conversation, 3: RuntimeSession} */
    private function escalatableConversation(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create(['status' => Agent::STATUS_ACTIVE]);
        $team->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        $conversation = Conversation::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-testvisitor0000001',
            'channel' => 'agent',
            'status' => 'active',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-testvisitor0000001',
            'flow_state' => 'discovery',
            'variables' => [],
            'history' => [
                ['role' => 'user', 'content' => 'Can I talk to a person?'],
            ],
        ]);

        return [$user, $agent, $conversation, $session];
    }

    public function test_escalation_marks_conversation_and_notifies_with_context(): void
    {
        Notification::fake();
        [$user, $agent, $conversation, $session] = $this->escalatableConversation();

        $context = new ConversationContext($agent, $session, 'I want to speak to a representative');
        app(EscalateToHuman::class)->handle($context, 'Visitor asked for a person');

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->meta['handoff_requested']);
        $this->assertSame('Visitor asked for a person', $conversation->meta['handoff_reason']);

        Notification::assertSentTo($user, HandoffRequestedNotification::class, function ($n) use ($conversation) {
            return $n->conversationId === $conversation->id
                && $n->contact === null
                && str_contains($n->lastMessage, 'representative');
        });
    }

    public function test_escalation_notifies_once_per_session_and_carries_contact(): void
    {
        Notification::fake();
        [$user, $agent, , $session] = $this->escalatableConversation();

        Lead::create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-testvisitor0000001',
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'status' => 'new',
            'source' => 'chat',
        ]);

        $context = new ConversationContext($agent, $session, 'help');
        $escalate = app(EscalateToHuman::class);
        $escalate->handle($context, 'first');
        $escalate->handle($context, 'second'); // same session — no second email

        Notification::assertSentToTimes($user, HandoffRequestedNotification::class, 1);
        Notification::assertSentTo($user, HandoffRequestedNotification::class, fn ($n) => str_contains((string) $n->contact, 'maria@example.com'));
    }

    public function test_handoff_tool_demands_contact_when_none_captured(): void
    {
        Notification::fake();
        [, $agent, , $session] = $this->escalatableConversation();

        $context = new ConversationContext($agent, $session, 'human please');
        $result = app(RequestHandoffTool::class)->execute(['reason' => 'asked'], $context);

        $this->assertIsArray($result);
        $this->assertStringContainsString('NO contact details', $result['message']);
        $this->assertStringContainsString('capture_lead', $result['message']);
    }

    public function test_owner_reply_takes_over_records_broadcasts_and_seeds_history(): void
    {
        Event::fake([LeadMessage::class]);
        [$user, , $conversation, $session] = $this->escalatableConversation();

        $this->actingAs($user)
            ->postJson(route('conversations.reply', $conversation), ['message' => 'Hi, Neo here — happy to help.'])
            ->assertOk()
            ->assertJsonPath('takeover', true)
            ->assertJsonPath('message.role', 'human');

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->meta['human_takeover']);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'human',
            'text' => 'Hi, Neo here — happy to help.',
        ]);

        // Session history gains an assistant turn so a later release keeps
        // the LLM contextually aware — and roles keep alternating.
        $history = $session->fresh()->history;
        $last = end($history);
        $this->assertSame('assistant', $last['role']);
        $this->assertStringContainsString('Neo here', $last['content']);

        Event::assertDispatched(LeadMessage::class, fn ($e) => $e->role === 'human'
            && $e->conversationId === $conversation->id);
    }

    public function test_reply_denied_for_other_teams_and_ended_conversations(): void
    {
        [, , $conversation] = $this->escalatableConversation();

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)
            ->postJson(route('conversations.reply', $conversation), ['message' => 'hi'])
            ->assertForbidden();

        $conversation->forceFill(['status' => 'ended'])->save();
        $owner = User::find($conversation->team->user_id);
        $this->actingAs($owner)
            ->postJson(route('conversations.reply', $conversation), ['message' => 'hi'])
            ->assertStatus(422);
    }

    public function test_takeover_pauses_the_ai_on_embed_interact_without_charging(): void
    {
        [, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true, 'human_takeover' => true]])->save();
        $startBalance = $agent->team->fresh()->totalCredits();

        $this->postJson(route('embed.interact', $agent->slug), [
            'visitor_id' => 'embed-testvisitor0000001',
            'message' => 'are you a real person now?',
        ])
            ->assertOk()
            ->assertJsonPath('takeover', true)
            ->assertJsonPath('traces', []);

        // Visitor message recorded for the dashboard; no LLM, no debit.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'text' => 'are you a real person now?',
        ]);
        $this->assertSame($startBalance, $agent->team->fresh()->totalCredits());
    }

    public function test_widget_poll_returns_only_new_human_replies_and_state(): void
    {
        [$user, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true, 'human_takeover' => true]])->save();

        $recorder = app(ConversationRecorder::class);
        $recorder->record($conversation, 'user', 'hello?');
        $first = $recorder->record($conversation, 'human', 'Hi — Neo here.');
        $recorder->record($conversation, 'agent', 'AI reply that must not be polled');
        $recorder->record($conversation, 'human', 'Still with you.');

        $response = $this->postJson(route('embed.poll', $agent->slug), [
            'visitor_id' => 'embed-testvisitor0000001',
            'after' => $first->getAttribute('sequence'),
        ])->assertOk()->assertJsonPath('takeover', true);

        $messages = $response->json('messages');
        $this->assertCount(1, $messages);
        $this->assertSame('Still with you.', $messages[0]['text']);
        $this->assertSame('human', $messages[0]['role']);
    }

    public function test_canned_answers_stand_down_during_handoff(): void
    {
        // Live-caught leak: a visitor's contact reply that keyword-matches a
        // chip ("…the Operator plan") must NOT be swallowed by the canned
        // path once escalated — the LLM owns handoff turns (contact capture).
        [, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true]])->save();

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['canned_answers' => [[
                'category' => 'Pricing',
                'keywords' => ['plan'],
                'answer' => 'Canned pricing spiel.',
            ]]],
            'published_at' => now(),
        ]);

        // Sanity: without handoff the same message IS canned.
        $control = Conversation::create([
            'team_id' => $agent->team_id, 'agent_id' => $agent->id,
            'visitor_id' => 'v-control', 'channel' => 'agent', 'status' => 'active',
            'started_at' => now(), 'last_message_at' => now(),
        ]);
        $this->postJson(route('embed.interact', $agent->slug), [
            'visitor_id' => 'v-control',
            'message' => 'tell me about the plan',
        ])->assertOk()->assertJsonPath('traces.0.payload.canned', true);

        // Escalated conversation: canned must NOT fire — the turn reaches the
        // runtime (faked LLM below; a canned hit would echo the spiel with
        // canned=true and never touch HTTP).
        config(['runtime.llm.anthropic.api_key' => 'sk-anthropic-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Got it — the team will call you.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ], 200),
        ]);

        $response = $this->postJson(route('embed.interact', $agent->slug), [
            'visitor_id' => 'embed-testvisitor0000001',
            'message' => 'call me about the Operator plan',
        ])->assertOk();

        $traces = $response->json('traces');
        foreach ($traces as $trace) {
            $this->assertNotSame('Canned pricing spiel.', $trace['payload']['message'] ?? '');
            $this->assertArrayNotHasKey('canned', $trace['payload'] ?? []);
        }
    }

    public function test_release_resumes_the_ai_path(): void
    {
        [$user, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true, 'human_takeover' => true]])->save();

        $this->actingAs($user)
            ->postJson(route('conversations.release', $conversation))
            ->assertOk()
            ->assertJsonPath('takeover', false)
            ->assertJsonPath('handoff', false);

        $fresh = $conversation->fresh();
        $this->assertFalse((bool) $fresh->meta['human_takeover']);
        // Escalation cleared too — otherwise the widget polls forever, the
        // conversation is stuck in "Needs human", and canned answers stay off.
        $this->assertFalse((bool) $fresh->meta['handoff_requested']);
    }

    public function test_release_drops_the_conversation_out_of_the_needs_human_queue(): void
    {
        [$user, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true, 'human_takeover' => true]])->save();

        $this->actingAs($user)->postJson(route('conversations.release', $conversation))->assertOk();

        $this->actingAs($user)
            ->get(route('conversations.index', ['needs_human' => 1]))
            ->assertInertia(fn ($page) => $page->has('conversations.data', 0));
    }

    public function test_poll_rejects_non_embed_visitor_ids(): void
    {
        [, $agent] = $this->escalatableConversation();

        // A Slack-style id (semi-public within a workspace) must not be able to
        // read teammate replies through the public, CSRF-exempt poll endpoint.
        $this->postJson(route('embed.poll', $agent->slug), [
            'visitor_id' => 'slack:U123:C456',
        ])->assertStatus(422);
    }

    public function test_needs_human_filter_lists_open_escalations_only(): void
    {
        [$user, $agent, $conversation] = $this->escalatableConversation();
        $conversation->forceFill(['meta' => ['handoff_requested' => true]])->save();

        // Noise: ended escalation + plain conversation.
        Conversation::create([
            'team_id' => $agent->team_id, 'agent_id' => $agent->id,
            'visitor_id' => 'v-ended', 'channel' => 'agent', 'status' => 'ended',
            'meta' => ['handoff_requested' => true],
            'started_at' => now(), 'last_message_at' => now(),
        ]);
        Conversation::create([
            'team_id' => $agent->team_id, 'agent_id' => $agent->id,
            'visitor_id' => 'v-plain', 'channel' => 'agent', 'status' => 'active',
            'started_at' => now(), 'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('conversations.index', ['needs_human' => 1]))
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.id', $conversation->id)
            );
    }
}
