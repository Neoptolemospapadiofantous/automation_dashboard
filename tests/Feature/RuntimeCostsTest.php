<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\RuntimeUsage;
use App\Models\User;
use App\Runtime\Contracts\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cost loop: FlowExecutor writes durable per-day token rollups;
 * runtime:costs turns them into the platform margin view; embed
 * greetings past the daily allowance debit a credit.
 */
class RuntimeCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.llm.anthropic.api_key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hi there!']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
            ]),
        ]);
    }

    public function test_every_turn_records_durable_usage_rollups(): void
    {
        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;

        $runtime = app(Runtime::class);
        $runtime->launch($agent, 'v1');
        $runtime->sendText($agent, 'v1', 'hello');

        $row = RuntimeUsage::where('team_id', $user->currentTeam->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(2, $row->turns);
        $this->assertSame(240, $row->tokens_in);  // 120 × 2 turns
        $this->assertSame(90, $row->tokens_out);  // 45 × 2 turns
        $this->assertSame(now()->toDateString(), $row->date->toDateString());
    }

    public function test_costs_command_reports_margin_per_team(): void
    {
        $user = $this->owner();
        RuntimeUsage::create([
            'team_id' => $user->currentTeam->id,
            'agent_id' => $user->currentTeam->currentAgent->id,
            'date' => now()->toDateString(),
            'turns' => 1000,
            'tokens_in' => 2_000_000,  // ×$1/MTok = $2.00
            'tokens_out' => 1_000_000, // ×$5/MTok = $5.00
        ]);

        $this->artisan('runtime:costs')
            ->expectsOutputToContain('Runtime costs')
            ->expectsOutputToContain('$7.00') // LLM cost
            ->assertExitCode(0);
    }

    public function test_costs_command_handles_empty_month(): void
    {
        $this->artisan('runtime:costs', ['--month' => '2020-01'])
            ->expectsOutputToContain('No runtime usage recorded')
            ->assertExitCode(0);
    }

    public function test_embed_greetings_past_the_daily_cap_debit_a_credit(): void
    {
        config(['runtime.safety.free_greetings_per_day' => 2]);

        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $user->currentTeam->forceFill(['credit_balance' => 10])->save();

        // Two free greetings (no cookie sent → each launch is a new visitor).
        $this->postJson("/embed/{$agent->slug}/launch")->assertOk();
        $this->postJson("/embed/{$agent->slug}/launch")->assertOk();
        $this->assertSame(10, $user->currentTeam->fresh()->credit_balance);

        // Third greeting of the day: debited.
        $this->postJson("/embed/{$agent->slug}/launch")->assertOk();
        $this->assertSame(9, $user->currentTeam->fresh()->credit_balance);
    }

    public function test_over_cap_greeting_with_zero_balance_is_rejected(): void
    {
        config(['runtime.safety.free_greetings_per_day' => 0]);

        $user = $this->owner();
        $agent = $user->currentTeam->currentAgent;
        $user->currentTeam->forceFill(['credit_balance' => 1])->save();

        // Cap 0 → every greeting debits. First eats the last credit…
        $this->postJson("/embed/{$agent->slug}/launch")->assertOk();
        // …second fails the hasCredits pre-check with a clean 402.
        $this->postJson("/embed/{$agent->slug}/launch")->assertStatus(402);
    }

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => 100])->save();

        return $user->fresh();
    }
}
