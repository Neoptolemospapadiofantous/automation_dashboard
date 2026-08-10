<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Models\User;
use App\Runtime\Canned\CannedAnswers;
use Database\Seeders\LandingFaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingFaqSeederTest extends TestCase
{
    use RefreshDatabase;

    private function landingAgent(): Agent
    {
        // Team 1's agent is the landing agent in every environment.
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['id' => 1])->save();
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        return $agent;
    }

    public function test_seeds_published_canned_answers_onto_the_landing_agent(): void
    {
        $agent = $this->landingAgent();

        $this->seed(LandingFaqSeeder::class);

        $canned = CannedAnswers::forAgent($agent->id);
        $this->assertContains('Pricing', $canned->chips());
        $this->assertContains('Custom build', $canned->chips());
        $this->assertContains('Integrations', $canned->chips());
        $this->assertContains('Book the audit', $canned->chips());
        // "audit" now routes to the audit chip, not Custom build.
        $this->assertSame('Book the audit', $canned->match('how do I book the free audit?')?->category);
        // Keyword + chip-tap both resolve.
        $this->assertSame('Pricing', $canned->match('how much does it cost?')?->category);
        $this->assertSame('Custom build', $canned->match('Custom build')?->category);
    }

    public function test_integration_questions_route_to_the_integrations_answer(): void
    {
        // The real visitor questions that used to draw the generic custom-build
        // blurb (prod convs 53 + 69) must land on the Integrations answer.
        $this->landingAgent();
        $this->seed(LandingFaqSeeder::class);

        $canned = CannedAnswers::forAgent(Agent::first()->id);
        $this->assertSame(
            'Integrations',
            $canned->match('Can you connect to my HubSpot CRM and book meetings on my Google Calendar?')?->category
        );
        $this->assertSame(
            'Integrations',
            $canned->match('Do you integrate with a Shopify store running on a custom subdomain?')?->category
        );
        // Role questions must not hit Custom build via the "custom" stem, and
        // credit-rollover questions must fall through to the grounded LLM.
        $this->assertNull($canned->match('do you do customer support?'));
        $this->assertNull($canned->match('if I dont use my monthly allowance, does it roll over to next month?'));
    }

    public function test_is_idempotent_and_does_not_churn_versions(): void
    {
        $agent = $this->landingAgent();

        $this->seed(LandingFaqSeeder::class);
        $afterFirst = AgentConfigVersion::where('agent_id', $agent->id)->count();

        $this->seed(LandingFaqSeeder::class);
        $afterSecond = AgentConfigVersion::where('agent_id', $agent->id)->count();

        // Second run is a no-op — no new archived version.
        $this->assertSame($afterFirst, $afterSecond);
    }

    public function test_preserves_existing_published_config_keys(): void
    {
        $agent = $this->landingAgent();
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['instructions' => 'be nice'],
            'published_at' => now(),
        ]);

        $this->seed(LandingFaqSeeder::class);

        $config = AgentConfigVersion::publishedConfig($agent->id);
        $this->assertSame('be nice', $config['instructions']); // untouched
        $this->assertNotEmpty($config['canned_answers']);       // added
    }
}
