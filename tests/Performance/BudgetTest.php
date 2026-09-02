<?php

namespace Tests\Performance;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CI performance budgets (strategy G1). Budgets live in config/sla.php and
 * are deliberately generous — CI runners are noisy, so these catch
 * order-of-magnitude regressions (an accidental N+1 explosion, a stray
 * sleep, an unfaked network call), not fine tuning. SLA changes must update
 * config/sla.php and these tests together (see CONTRIBUTING.md).
 */
class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_within_ci_budget(): void
    {
        $user = $this->owner();

        $start = hrtime(true);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertLessThan(
            config('sla.ci_budget.dashboard_ms', 2000),
            $elapsedMs,
            sprintf('Dashboard render took %.0f ms — order-of-magnitude regression?', $elapsedMs),
        );
    }

    public function test_chat_launch_turn_completes_within_ci_budget(): void
    {
        // Provider faked: the budget measures OUR pipeline around the LLM
        // call (auth, credit pre-check, runtime dispatch, recording,
        // billing, broadcast) — real-turn latency is provider-dominated
        // and out of scope for CI.
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello! What brings you here?'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello! What brings you here?']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ]),
        ]);
        config(['runtime.llm.anthropic.api_key' => 'sk-anthropic-test']);

        $user = $this->owner();
        $user->currentTeam->forceFill(['credit_balance' => 100])->save();

        $start = hrtime(true);
        $this->actingAs($user)->postJson(route('chat.launch'))->assertOk();
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $this->assertLessThan(
            config('sla.ci_budget.chat_turn_ms', 3000),
            $elapsedMs,
            sprintf('chat.launch took %.0f ms with a faked provider — order-of-magnitude regression?', $elapsedMs),
        );
    }

    /**
     * Authed fixture, same shape as tests/Feature/DashboardSetupChecklistTest.php.
     */
    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        return $user->fresh();
    }
}
