<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use App\Runtime\LLM\SystemPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Versioned agent behavior — the native successor to the old Environments
 * page: draft → publish → archive → restore, with the published config
 * injected into the engine's system prompt on the very next turn.
 */
class AgentVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_with_no_versions(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->get(route('agents.versions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Versions')
                ->where('versions', [])
                ->where('draft', null)
            );
    }

    public function test_save_draft_creates_then_updates_a_single_draft(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => 'Always ask about practice size.',
            'greeting' => 'Mention the webinar.',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => 'Updated instructions.',
            'greeting' => '',
        ])->assertRedirect();

        // One draft row, updated in place — not two.
        $drafts = AgentConfigVersion::where('status', 'draft')->get();
        $this->assertCount(1, $drafts);
        $this->assertSame('Updated instructions.', $drafts->first()->config['instructions']);
        $this->assertSame(1, $drafts->first()->version);
    }

    public function test_publish_promotes_draft_and_archives_previous_live(): void
    {
        $user = $this->owner();

        // v1: draft → publish
        $this->actingAs($user)->post(route('agents.versions.draft'), ['instructions' => 'v1 rules', 'greeting' => '']);
        $this->actingAs($user)->post(route('agents.versions.publish'))->assertRedirect();

        // v2: new draft → publish
        $this->actingAs($user)->post(route('agents.versions.draft'), ['instructions' => 'v2 rules', 'greeting' => '']);
        $this->actingAs($user)->post(route('agents.versions.publish'))->assertRedirect();

        $this->assertSame('archived', AgentConfigVersion::where('version', 1)->first()->status);
        $live = AgentConfigVersion::where('status', 'published')->get();
        $this->assertCount(1, $live);
        $this->assertSame('v2 rules', $live->first()->config['instructions']);
        $this->assertNotNull($live->first()->published_at);
    }

    public function test_publish_without_draft_errors_cleanly(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('agents.versions.publish'))
            ->assertRedirect()
            ->assertSessionHasErrors('publish');
    }

    public function test_restore_copies_an_archived_version_into_the_draft(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('agents.versions.draft'), ['instructions' => 'v1 rules', 'greeting' => '']);
        $this->actingAs($user)->post(route('agents.versions.publish'));
        $this->actingAs($user)->post(route('agents.versions.draft'), ['instructions' => 'v2 rules', 'greeting' => '']);
        $this->actingAs($user)->post(route('agents.versions.publish'));

        // Roll back: restore v1 into a fresh draft, publish it as v3.
        $this->actingAs($user)->post(route('agents.versions.restore', 1))->assertRedirect();

        $draft = AgentConfigVersion::where('status', 'draft')->first();
        $this->assertSame('v1 rules', $draft->config['instructions']);

        $this->actingAs($user)->post(route('agents.versions.publish'));
        $this->assertSame('v1 rules', AgentConfigVersion::where('status', 'published')->first()->config['instructions']);
        // History stays linear — v1 and v2 are archived, v3 is live.
        $this->assertSame(3, AgentConfigVersion::count());
    }

    public function test_export_downloads_version_json(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post(route('agents.versions.draft'), ['instructions' => 'exportable', 'greeting' => '']);

        $this->actingAs($user)->get(route('agents.versions.export', 1))
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->assertJsonPath('config.instructions', 'exportable')
            ->assertJsonPath('version', 1);
    }

    public function test_member_cannot_edit_or_publish(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'member']);
        $member->switchTeam($owner->currentTeam);

        $this->actingAs($member->fresh())->post(route('agents.versions.draft'), [
            'instructions' => 'sneaky', 'greeting' => '',
        ])->assertStatus(403);

        $this->actingAs($member->fresh())->post(route('agents.versions.publish'))->assertStatus(403);
    }

    public function test_published_config_is_injected_into_the_engine_prompt(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'published',
            'config' => ['instructions' => 'Always ask about practice size.', 'greeting' => 'Mention the March webinar.'],
            'published_at' => now(),
        ]);

        // A published greeting short-circuits launch: served verbatim,
        // no LLM call at all.
        $traces = app(Runtime::class)->launch($agent, 'v-cfg-1');
        $this->assertSame('Mention the March webinar.', $traces[0]['payload']['message']);
        Http::assertNothingSent();

        // The first real turn injects the published instructions into the
        // engine's system prompt.
        app(Runtime::class)->sendText($agent, 'v-cfg-1', 'What do you offer?');

        Http::assertSent(function ($request): bool {
            $system = $this->systemTextOf($request);

            return str_contains($system, 'Always ask about practice size.');
        });
    }

    public function test_draft_config_is_not_injected(): void
    {
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'draft',
            'config' => ['instructions' => 'DRAFT ONLY — must not leak.', 'greeting' => ''],
        ]);

        app(Runtime::class)->launch($agent, 'v-cfg-2');

        Http::assertSent(fn ($request): bool => ! str_contains(SystemPrompt::toText($request->data()['system'] ?? ''), 'DRAFT ONLY'));
    }

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user->fresh();
    }
}
