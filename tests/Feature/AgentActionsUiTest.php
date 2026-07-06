<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator Actions editor (phase-16) — authoring n8n automations into the
 * agent's draft config. The load-bearing guarantee is that this page and the
 * behavior editor write disjoint keys of the SAME draft without clobbering
 * each other.
 */
class AgentActionsUiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authoring Actions is a managed-service surface gated to Hermes operators,
     * so the acting user is registered into the operator allowlist.
     *
     * @return array{0: User, 1: Agent}
     */
    private function userWithAgent(bool $operator = true): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        if ($operator) {
            config(['hermes.operators' => array_merge(config('hermes.operators', []), [$user->email])]);
        }

        return [$user, $agent];
    }

    public function test_index_forbidden_for_non_operator(): void
    {
        [$user] = $this->userWithAgent(operator: false);
        config(['hermes.operators' => []]);

        $this->actingAs($user)->get(route('agents.actions.index'))->assertForbidden();
    }

    public function test_save_forbidden_for_non_operator(): void
    {
        [$user] = $this->userWithAgent(operator: false);
        config(['hermes.operators' => []]);

        $this->actingAs($user)->post(route('agents.actions.save'), [
            'automations' => [[
                'name' => 'x', 'url' => 'https://n8n.flowstack.run/a', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => [],
            ]],
        ])->assertForbidden();
    }

    public function test_shares_automations_enabled_flag_that_gates_the_coming_soon_state(): void
    {
        [$user] = $this->userWithAgent();

        config(['runtime.automation.enabled' => false]);
        $this->actingAs($user)->get(route('agents.actions.index'))
            ->assertInertia(fn ($page) => $page->where('automationsEnabled', false));

        config(['runtime.automation.enabled' => true]);
        $this->actingAs($user)->get(route('agents.actions.index'))
            ->assertInertia(fn ($page) => $page->where('automationsEnabled', true));
    }

    public function test_index_renders_seeded_from_published(): void
    {
        [$user, $agent] = $this->userWithAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['instructions' => 'be nice', 'automations' => [
                ['id' => '01JZFAKEULID00000000000000', 'name' => 'lookup_order', 'url' => 'https://n8n.flowstack.run/webhook/o', 'mode' => 'sync', 'credit_cost' => 3,
                    'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'order id']], 'required' => ['id']]],
            ]],
        ]);

        $this->actingAs($user)->get(route('agents.actions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Actions')
                ->has('publishedActions', 1)
                ->where('publishedActions.0.id', '01JZFAKEULID00000000000000')
                ->where('publishedActions.0.name', 'lookup_order')
                ->where('publishedActions.0.parameters.0.name', 'id')
                ->where('publishedActions.0.parameters.0.required', true)
            );
    }

    public function test_save_normalizes_name_and_builds_json_schema(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $this->actingAs($user)->post(route('agents.actions.save'), [
            'automations' => [[
                'name' => 'Look Up Order!',
                'description' => 'find an order',
                'url' => 'https://n8n.flowstack.run/webhook/o',
                'mode' => 'sync',
                'credit_cost' => 3,
                'parameters' => [
                    ['name' => 'Order ID', 'type' => 'string', 'description' => 'the number', 'required' => true],
                ],
            ]],
        ])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $action = $draft->config['automations'][0];
        $this->assertSame('look_up_order', $action['name']);
        $this->assertSame('object', $action['parameters']['type']);
        $this->assertSame('string', $action['parameters']['properties']['order_id']['type']);
        $this->assertSame(['order_id'], $action['parameters']['required']);
    }

    public function test_save_preserves_behavior_keys_in_draft(): void
    {
        [$user, $agent] = $this->userWithAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_DRAFT,
            'config' => ['instructions' => 'keep me', 'model_tier' => 'sonnet'],
        ]);

        $this->actingAs($user)->post(route('agents.actions.save'), [
            'automations' => [[
                'name' => 'ping', 'url' => 'https://n8n.flowstack.run/webhook/p', 'mode' => 'async', 'credit_cost' => 1, 'parameters' => [],
            ]],
        ])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $this->assertSame('keep me', $draft->config['instructions']);
        $this->assertSame('sonnet', $draft->config['model_tier']);
        $this->assertSame('ping', $draft->config['automations'][0]['name']);
    }

    public function test_behavior_save_preserves_staged_automations(): void
    {
        [$user, $agent] = $this->userWithAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_DRAFT,
            'config' => ['automations' => [
                ['name' => 'keep_me', 'url' => 'https://n8n.flowstack.run/webhook/k', 'mode' => 'sync', 'credit_cost' => 1,
                    'parameters' => ['type' => 'object', 'properties' => (object) []]],
            ]],
        ]);

        $this->actingAs($user)->post(route('agents.versions.draft'), [
            'instructions' => 'new behavior',
        ])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $this->assertSame('new behavior', $draft->config['instructions']);
        $this->assertSame('keep_me', $draft->config['automations'][0]['name'], 'behavior save must not wipe staged actions');
    }

    public function test_save_rejects_http_url_when_private_hosts_disallowed(): void
    {
        config(['runtime.automation.allow_private_hosts' => false]);
        [$user] = $this->userWithAgent();

        $this->actingAs($user)->post(route('agents.actions.save'), [
            'automations' => [[
                'name' => 'insecure', 'url' => 'http://n8n.flowstack.run/webhook/x', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => [],
            ]],
        ])->assertSessionHasErrors('automations.0.url');
    }

    public function test_save_mints_stable_id_and_rename_preserves_it(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $payload = fn (string $name, string $id = '') => ['automations' => [[
            'id' => $id, 'name' => $name, 'url' => 'https://n8n.flowstack.run/webhook/o', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => [],
        ]]];

        // First save: no id sent → one is minted.
        $this->actingAs($user)->post(route('agents.actions.save'), $payload('lookup_order'))->assertRedirect();
        $draft = fn () => AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $minted = $draft()->config['automations'][0]['id'];
        $this->assertSame(26, strlen($minted), 'a ULID id is minted for a new action');

        // Rename, round-tripping the id → id survives, name changes.
        $this->actingAs($user)->post(route('agents.actions.save'), $payload('find_order', $minted))->assertRedirect();
        $action = $draft()->config['automations'][0];
        $this->assertSame($minted, $action['id'], 'rename must keep the stable id');
        $this->assertSame('find_order', $action['name']);
    }

    public function test_save_replaces_unknown_client_id_with_fresh_ulid(): void
    {
        [$user, $agent] = $this->userWithAgent();

        // An id the agent never minted (forged or copied from another agent)
        // must not be honoured — history can't be grafted onto it.
        $this->actingAs($user)->post(route('agents.actions.save'), ['automations' => [[
            'id' => '01JZFORGEDID00000000000000', 'name' => 'ping', 'url' => 'https://n8n.flowstack.run/webhook/p', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => [],
        ]]])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $id = $draft->config['automations'][0]['id'];
        $this->assertNotSame('01JZFORGEDID00000000000000', $id);
        $this->assertSame(26, strlen($id));
    }

    public function test_save_rejects_duplicate_names(): void
    {
        [$user] = $this->userWithAgent();

        $this->actingAs($user)->post(route('agents.actions.save'), [
            'automations' => [
                ['name' => 'dupe', 'url' => 'https://n8n.flowstack.run/a', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => []],
                ['name' => 'Dupe!', 'url' => 'https://n8n.flowstack.run/b', 'mode' => 'sync', 'credit_cost' => 1, 'parameters' => []],
            ],
        ])->assertSessionHasErrors('automations.1.name');
    }
}
