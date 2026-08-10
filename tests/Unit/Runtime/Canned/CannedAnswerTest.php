<?php

namespace Tests\Unit\Runtime\Canned;

use App\Runtime\Canned\CannedAnswer;
use Tests\TestCase;

class CannedAnswerTest extends TestCase
{
    public function test_from_config_normalizes_keywords_and_drops_blanks(): void
    {
        $a = CannedAnswer::fromConfig([
            'category' => 'Pricing',
            'keywords' => ['Price', ' COST ', '', 'price'],
            'answer' => 'Plans start at $99/mo.',
        ]);

        $this->assertNotNull($a);
        $this->assertSame('Pricing', $a->category);
        $this->assertSame(['price', 'cost'], $a->keywords); // lowered, trimmed, de-duped
        $this->assertSame('Plans start at $99/mo.', $a->answer);
    }

    public function test_from_config_returns_null_without_category_or_answer(): void
    {
        $this->assertNull(CannedAnswer::fromConfig(['category' => '', 'answer' => 'x']));
        $this->assertNull(CannedAnswer::fromConfig(['category' => 'x', 'answer' => '  ']));
    }

    public function test_matches_on_exact_category_regardless_of_case(): void
    {
        $a = CannedAnswer::fromConfig(['category' => 'Pricing', 'keywords' => [], 'answer' => 'x']);

        // A chip tap sends the category label verbatim.
        $this->assertTrue($a->matches('pricing'));
        $this->assertFalse($a->matches('tell me about your team'));
    }

    public function test_matches_on_whole_keyword_words_and_phrases(): void
    {
        $a = CannedAnswer::fromConfig([
            'category' => 'Pricing',
            'keywords' => ['how much', 'cost'],
            'answer' => 'x',
        ]);

        $this->assertTrue($a->matches('so how much is this?'));
        $this->assertTrue($a->matches('what does it cost'));
        $this->assertTrue($a->matches('cost?'));
        $this->assertFalse($a->matches('what features do you have'));
    }

    public function test_keywords_do_not_fire_inside_longer_words(): void
    {
        // A canned answer served for the wrong question reads as the agent
        // ignoring the visitor — "try" must not fire on "industry", "custom"
        // on "customer", "api" on "rapid".
        $try = CannedAnswer::fromConfig(['category' => 'Getting started', 'keywords' => ['try'], 'answer' => 'x']);
        $this->assertFalse($try->matches('do you support my industry?'));
        $this->assertTrue($try->matches('can i try it first?'));

        $custom = CannedAnswer::fromConfig(['category' => 'Custom build', 'keywords' => ['custom build'], 'answer' => 'x']);
        $this->assertFalse($custom->matches('do you do customer support?'));
        $this->assertTrue($custom->matches('tell me about the custom build'));

        $api = CannedAnswer::fromConfig(['category' => 'Integrations', 'keywords' => ['api'], 'answer' => 'x']);
        $this->assertFalse($api->matches('is setup rapid?'));
        $this->assertTrue($api->matches('do you have an api?'));
    }
}
