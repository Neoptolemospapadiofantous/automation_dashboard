<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator FAQ editor — authoring deterministic canned answers into the
 * agent's draft config (config.canned_answers), sharing the same draft the
 * behavior + Actions editors write. Operator-gated like Actions.
 */
class AgentFaqUiTest extends TestCase
{
    use RefreshDatabase;

    /**
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

        $this->actingAs($user)->get(route('agents.faq.index'))->assertForbidden();
    }

    public function test_save_forbidden_for_non_operator(): void
    {
        [$user] = $this->userWithAgent(operator: false);
        config(['hermes.operators' => []]);

        $this->actingAs($user)->post(route('agents.faq.save'), [
            'answers' => [['category' => 'Pricing', 'keywords' => 'cost', 'answer' => 'From $99.']],
        ])->assertForbidden();
    }

    public function test_index_renders_seeded_from_published(): void
    {
        [$user, $agent] = $this->userWithAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['canned_answers' => [
                ['category' => 'Pricing', 'keywords' => ['cost', 'price'], 'answer' => 'From $99/mo.'],
            ]],
        ]);

        $this->actingAs($user)->get(route('agents.faq.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Agents/Faq')
                ->has('publishedAnswers', 1)
                ->where('publishedAnswers.0.category', 'Pricing')
                ->where('publishedAnswers.0.keywords', 'cost, price') // array recombined for the text field
                ->where('publishedAnswers.0.answer', 'From $99/mo.')
            );
    }

    public function test_save_splits_keywords_and_stores_normalized(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $this->actingAs($user)->post(route('agents.faq.save'), [
            'answers' => [[
                'category' => 'Pricing',
                'keywords' => 'Cost,  How Much , price, cost',
                'answer' => 'Plans start at $99/mo.',
            ]],
        ])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $row = $draft->config['canned_answers'][0];
        $this->assertSame('Pricing', $row['category']);
        $this->assertSame(['cost', 'how much', 'price'], $row['keywords']); // lowered, trimmed, de-duped
        $this->assertSame('Plans start at $99/mo.', $row['answer']);
    }

    public function test_save_rejects_duplicate_category(): void
    {
        [$user, $agent] = $this->userWithAgent();

        $this->actingAs($user)->post(route('agents.faq.save'), [
            'answers' => [
                ['category' => 'Pricing', 'keywords' => 'cost', 'answer' => 'A'],
                ['category' => 'pricing', 'keywords' => 'price', 'answer' => 'B'],
            ],
        ])->assertSessionHasErrors('answers.1.category');
    }

    public function test_save_preserves_other_draft_keys(): void
    {
        [$user, $agent] = $this->userWithAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_DRAFT,
            'config' => ['instructions' => 'keep me', 'automations' => [['name' => 'a', 'url' => 'https://n8n.flowstack.run/x', 'mode' => 'sync']]],
        ]);

        $this->actingAs($user)->post(route('agents.faq.save'), [
            'answers' => [['category' => 'Pricing', 'keywords' => 'cost', 'answer' => 'From $99.']],
        ])->assertRedirect();

        $draft = AgentConfigVersion::where('agent_id', $agent->id)->where('status', 'draft')->firstOrFail();
        $this->assertSame('keep me', $draft->config['instructions']);
        $this->assertCount(1, $draft->config['automations']);
        $this->assertCount(1, $draft->config['canned_answers']);
    }
}
