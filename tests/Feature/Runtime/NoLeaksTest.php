<?php

namespace Tests\Feature\Runtime;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\HandoffRequestedNotification;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\ToolRegistry;
use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regression locks for the post-replacement audit findings — each test
 * pins one "leak" closed so it can't silently reopen.
 */
class NoLeaksTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_agent_is_configured_without_voiceflow_credentials(): void
    {
        // LEAK 1: isConfigured() checked VF columns only → native agents
        // were locked out of the Chat page + flagged "Needs credentials".
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);

        $this->assertTrue($agent->isConfigured());
    }

    public function test_chat_page_unlocks_for_native_agents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $this->actingAs($user->fresh())
            ->get(route('chat.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('configured', true));
    }

    public function test_for_agent_never_inherits_platform_env_credentials(): void
    {
        // LEAK 2 (cross-tenant): a credential-less agent must NOT fall back
        // to the platform's global VOICEFLOW_API_KEY / PROJECT_ID.
        config([
            'services.voiceflow.api_key' => 'VF.DM.platform-global-key',
            'services.voiceflow.project_id' => 'platform-global-project',
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'voiceflow_api_key' => null,
            'voiceflow_project_id' => null,
        ]);

        $service = VoiceflowService::forAgent($agent);

        $this->assertFalse(
            $service->isConfigured(),
            'forAgent() inherited the platform env credentials for a credential-less agent — cross-tenant leak.',
        );
    }

    public function test_request_handoff_actually_notifies_the_team_owner(): void
    {
        // LEAK 3: the tool told visitors "a teammate has been notified"
        // while notifying nobody.
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create(['runtime_mode' => 'native']);
        $session = RuntimeSession::create([
            'agent_id' => $agent->id, 'visitor_id' => 'v1', 'flow_state' => 'discovery',
            'variables' => [], 'history' => [],
        ]);

        app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'request_handoff', ['reason' => 'wants legal review']),
            new ConversationContext($agent, $session, 'msg'),
        );

        Notification::assertSentTo($owner, HandoffRequestedNotification::class, function ($n) {
            return $n->reason === 'wants legal review';
        });
    }

    public function test_capture_lead_writes_captured_as_field_array(): void
    {
        // LEAK 4: captured=true (bool) would TypeError the legacy
        // array_merge() upsert path. Must be the captured-fields map.
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create(['runtime_mode' => 'native']);
        $session = RuntimeSession::create([
            'agent_id' => $agent->id, 'visitor_id' => 'v1', 'flow_state' => 'discovery',
            'variables' => [], 'history' => [],
        ]);

        app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'capture_lead', ['name' => 'Bob', 'email' => 'bob@x.co', 'company' => 'X Co']),
            new ConversationContext($agent, $session, 'msg'),
        );

        $lead = Lead::where('email', 'bob@x.co')->first();
        $this->assertIsArray($lead->captured);
        $this->assertSame('Bob', $lead->captured['name']);
        $this->assertSame('X Co', $lead->captured['company']);
        // The legacy merge path must keep working on this row.
        $merged = array_merge($lead->captured ?? [], ['phone' => '+1']);
        $this->assertSame('+1', $merged['phone']);
    }

    public function test_engine_prop_is_shared_and_voiceflow_strings_are_gone_from_agent_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native', 'status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        // LEAK 5a: sidebar gating input — the engine prop must be shared.
        $this->actingAs($user->fresh())
            ->get(route('agents.show', $agent->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('engine', 'native'));
    }

    public function test_no_customer_visible_voiceflow_strings_in_core_pages(): void
    {
        // LEAK 5b: vendor name must not appear in customer-facing template
        // markup on the core pages (HTML comments + JS comments excluded by
        // checking only rendered-string contexts is overkill — a simple
        // "no Voiceflow outside comments" line scan keeps the bar).
        $pages = [
            'resources/js/Pages/Agents/Show.vue',
            'resources/js/Pages/Onboarding/Intro.vue',
            'resources/js/Pages/Onboarding/Done.vue',
            'resources/js/Pages/Install/Index.vue',
            'resources/js/Pages/Chat/Index.vue',
            'resources/js/Pages/Knowledge/Index.vue',
        ];

        foreach ($pages as $page) {
            $offending = [];
            foreach (explode("\n", (string) file_get_contents(base_path($page))) as $i => $line) {
                $t = trim($line);
                if (stripos($t, 'voiceflow') === false) {
                    continue;
                }
                // Allow comments (internal) — flag everything else.
                if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '<!--')) {
                    continue;
                }
                $offending[] = $page.':'.($i + 1).' — '.$t;
            }
            $this->assertSame([], $offending, 'Customer-visible Voiceflow strings remain');
        }
    }

    public function test_native_knowledge_type_filter_works(): void
    {
        config([
            'runtime.embeddings.openai_api_key' => 'sk-test',
            'runtime.embeddings.dimensions' => 4,
        ]);
        Http::fake([
            'api.openai.com/*' => function (ClientRequest $request) {
                $inputs = (array) $request->data()['input'];
                $data = [];
                foreach (array_values($inputs) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [0.5, 0.5, 0.5, 0.5]];
                }

                return Http::response(['data' => $data]);
            },
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native', 'status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        $kb = app(KnowledgeStore::class);
        $kb->ingestDocument($agent->id, 'A text doc', 'text content', ['source' => 'text']);
        $kb->ingestDocument($agent->id, 'A url doc', 'url content', ['source' => 'url', 'source_url' => 'https://x.co']);

        $this->actingAs($user->fresh())
            ->get(route('knowledge.index', ['type' => 'url']))
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('documents.0.data.name', 'A url doc')
                ->where('filter.type', 'url')
            );
    }
}
