<?php

namespace Tests\Unit\Runtime\Canned;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\User;
use App\Runtime\Canned\CannedAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CannedAnswersTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array<string, mixed>> $canned */
    private function agentWithCanned(array $canned, string $status = 'published'): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => $status,
            'config' => ['canned_answers' => $canned],
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return $agent;
    }

    public function test_reads_published_answers_and_exposes_chips_in_order(): void
    {
        $agent = $this->agentWithCanned([
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'From $99.'],
            ['category' => 'Features', 'keywords' => ['what can'], 'answer' => 'Lots.'],
        ]);

        $canned = CannedAnswers::forAgent($agent->id);

        $this->assertSame(['Pricing', 'Features'], $canned->chips());
    }

    public function test_draft_answers_are_invisible(): void
    {
        $agent = $this->agentWithCanned([
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'From $99.'],
        ], status: 'draft');

        $this->assertSame([], CannedAnswers::forAgent($agent->id)->chips());
        $this->assertNull(CannedAnswers::forAgent($agent->id)->match('cost'));
    }

    public function test_match_returns_first_matching_answer(): void
    {
        $agent = $this->agentWithCanned([
            ['category' => 'Pricing', 'keywords' => ['cost', 'price'], 'answer' => 'From $99.'],
            ['category' => 'Features', 'keywords' => ['feature'], 'answer' => 'Lots.'],
        ]);

        $canned = CannedAnswers::forAgent($agent->id);

        $this->assertSame('From $99.', $canned->match('what does it cost?')?->answer);
        $this->assertSame('Lots.', $canned->match('Features')?->answer);
        $this->assertNull($canned->match('do you integrate with Slack?'));
        $this->assertNull($canned->match('   '));
    }

    public function test_duplicate_category_keeps_the_first(): void
    {
        $agent = $this->agentWithCanned([
            ['category' => 'Pricing', 'keywords' => ['cost'], 'answer' => 'First.'],
            ['category' => 'pricing', 'keywords' => ['cost'], 'answer' => 'Second.'],
        ]);

        $canned = CannedAnswers::forAgent($agent->id);

        $this->assertSame(['Pricing'], $canned->chips());
        $this->assertSame('First.', $canned->match('cost')?->answer);
    }
}
