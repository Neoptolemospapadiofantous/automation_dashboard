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

    public function test_matches_on_keyword_substring(): void
    {
        $a = CannedAnswer::fromConfig([
            'category' => 'Pricing',
            'keywords' => ['how much', 'cost'],
            'answer' => 'x',
        ]);

        $this->assertTrue($a->matches('so how much is this?'));
        $this->assertTrue($a->matches('what does it cost'));
        $this->assertFalse($a->matches('what features do you have'));
    }
}
