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
        // The seeder resolves its target by LANDING_AGENT_SLUG first, falling
        // back to team 1. Point it at the slug rather than renumbering the team
        // to id 1: a free team is granted its allotment on creation, so it
        // already owns credit_transactions rows and the FK refuses the id
        // rewrite.
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();
        config(['runtime.landing_agent_slug' => $agent->slug]);

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

    public function test_public_api_chip_answers_api_questions_without_stealing_the_own_key_ones(): void
    {
        // "Do you have an API?" used to fall through to the LLM, where the
        // low-confidence backstop can escalate — for a question whose honest
        // answer (no public API; widget + hosted page + custom builds) is fixed.
        $agent = $this->landingAgent();

        $this->seed(LandingFaqSeeder::class);

        $canned = CannedAnswers::forAgent($agent->id);
        foreach (['do you have an API I can call?', 'is there a public API?', 'do you offer a REST API or SDK?', 'where are the API docs?'] as $q) {
            $this->assertSame('Public API', $canned->match($q)?->category, "'{$q}' must land on the Public API chip.");
        }

        // The collisions the chip must NOT cause — first match wins.
        $this->assertSame('Your own key', $canned->match('can I use my own API key?')?->category);
        $this->assertSame('Pricing', $canned->match('how much does it cost?')?->category);
        $this->assertNull($canned->match('where do I get my API key for the widget?'), 'a bare "api" must not be a keyword');
        $this->assertNull($canned->match('what are the key features?'));
    }

    public function test_buy_intent_reaches_getting_started_with_the_real_signup_url(): void
    {
        // A real visitor asked "the link to buy it" and the chat answered it
        // had no buy link — the chip offered "want the signup link?" while
        // nothing downstream could produce one (conv #169, 2026-08-30).
        $agent = $this->landingAgent();

        $this->seed(LandingFaqSeeder::class);

        $canned = CannedAnswers::forAgent($agent->id);
        foreach ([
            'give me the link to buy it', 'how do i pay', 'where do i buy', 'buy it',
            'i want to buy', 'how do i subscribe', 'checkout', 'where do i register',
            'create an account', 'send me the signup link', 'where do i sign up',
            'give me the link',
        ] as $q) {
            $this->assertSame('Getting started', $canned->match($q)?->category, "'{$q}' must land on Getting started.");
        }
        $this->assertStringContainsString('app.flowstack.run/register', $canned->match('buy it')->answer);

        // The collisions the new keywords must NOT cause.
        $this->assertNull($canned->match("what's the link to your linkedin?"), "bare 'link' must not be a keyword");
        $this->assertNull($canned->match('send me the link'), 'only the exact observed phrase is safe — the rest of the bare-link family belongs to the (now correctly grounded) LLM path');
        $this->assertSame('Book the audit', $canned->match('can you send me the link to the audit page?')?->category);
        $this->assertSame('Your own key', $canned->match('can I use my own API key?')?->category);
        $this->assertSame('Pricing', $canned->match('how much does it cost?')?->category);
        $this->assertSame('Pricing', $canned->match('how much do I pay each month?')?->category, 'Pricing sits before Getting started and keeps price questions');
    }

    public function test_chip_order_and_pricing_rules_hold(): void
    {
        // These two invariants have each been lost once in production, so they
        // are asserted rather than trusted:
        //
        //  1. ORDER — CannedAnswers takes the FIRST match, so a generic chip
        //     ahead of a specific one swallows it. 'Pricing' (keyword
        //     `how much`) once intercepted "how much does a custom build
        //     cost?" and answered with plan prices.
        //  2. NO BUILD PRICE — build work is quoted after the audit; a figure
        //     in the custom-build answer contradicts the whole site.
        $method = new \ReflectionMethod(LandingFaqSeeder::class, 'chips');
        $method->setAccessible(true);
        /** @var list<array{category: string, keywords: list<string>, answer: string, escalate?: bool}> $chips */
        $chips = $method->invoke(new LandingFaqSeeder);

        $position = array_flip(array_column($chips, 'category'));
        foreach ([['Custom build', 'Pricing'], ['Book the audit', 'Getting started'], ['Outreach', 'What it does'], ['Your own key', 'Pricing'], ['Your own key', 'Public API']] as [$specific, $generic]) {
            $this->assertArrayHasKey($specific, $position, "Chip '{$specific}' is missing.");
            $this->assertArrayHasKey($generic, $position, "Chip '{$generic}' is missing.");
            $this->assertLessThan(
                $position[$generic],
                $position[$specific],
                "'{$specific}' must be matched before '{$generic}' — first match wins, so the generic chip would swallow it.",
            );
        }

        $customBuild = $chips[$position['Custom build']]['answer'];
        $this->assertDoesNotMatchRegularExpression(
            '/€\s?\d/',
            $customBuild,
            'The custom-build answer must not quote a price — build work is quoted after the audit.',
        );

        // A retired price surviving here is the exact drift that makes the
        // cheapest, most-read turns on the site contradict the pricing page.
        $everyAnswer = implode(' ', array_column($chips, 'answer'));
        foreach (['€99', '€399', '€179', 'no free trial'] as $retired) {
            $this->assertStringNotContainsString($retired, $everyAnswer, "Retired pricing copy '{$retired}' is still being served.");
        }

        // The human-handoff chip must keep firing the escalation.
        $human = $chips[$position['Talk to a human']];
        $this->assertTrue(($human['escalate'] ?? false), 'The "Talk to a human" chip must escalate.');
    }

    public function test_answers_stay_short_and_end_on_a_question(): void
    {
        // These render in a chat bubble, not on a page, and they are the
        // most-read turns on the site. Two rules from the copy pass:
        // a hard length ceiling, and a forward-moving close so a canned
        // answer never dead-ends the conversation.
        $method = new \ReflectionMethod(LandingFaqSeeder::class, 'chips');
        $method->setAccessible(true);
        /** @var list<array{category: string, keywords: list<string>, answer: string, escalate?: bool}> $chips */
        $chips = $method->invoke(new LandingFaqSeeder);

        foreach ($chips as $chip) {
            $words = str_word_count($chip['answer']);
            $this->assertLessThanOrEqual(
                75,
                $words,
                "The '{$chip['category']}' answer is {$words} words — too long for a chat bubble.",
            );
            $this->assertStringEndsWith(
                '?',
                rtrim($chip['answer']),
                "The '{$chip['category']}' answer must end on a question that moves the conversation on.",
            );
        }
    }
}
