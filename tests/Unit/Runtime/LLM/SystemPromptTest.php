<?php

namespace Tests\Unit\Runtime\LLM;

use App\Runtime\LLM\SystemPrompt;
use Tests\TestCase;

class SystemPromptTest extends TestCase
{
    public function test_blocks_marks_the_stable_prefix_with_cache_control(): void
    {
        $blocks = SystemPrompt::blocks(['persona', 'catalog'], ['objective', 'kb']);

        $this->assertIsArray($blocks);
        $this->assertCount(2, $blocks);
        $this->assertSame("persona\n\ncatalog", $blocks[0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $blocks[0]['cache_control']);
        $this->assertSame("objective\n\nkb", $blocks[1]['text']);
        $this->assertArrayNotHasKey('cache_control', $blocks[1]);
    }

    public function test_blocks_drops_empty_segments(): void
    {
        $blocks = SystemPrompt::blocks(['persona', '', '  '], ['objective']);

        $this->assertSame('persona', $blocks[0]['text']);
        $this->assertSame('objective', $blocks[1]['text']);
    }

    public function test_blocks_with_no_stable_content_returns_plain_string(): void
    {
        // Nothing worth caching → a plain string, so short prompts never pay
        // the cache-write premium.
        $result = SystemPrompt::blocks([], ['objective', 'kb']);

        $this->assertSame("objective\n\nkb", $result);
    }

    public function test_blocks_with_only_stable_omits_the_dynamic_block(): void
    {
        $blocks = SystemPrompt::blocks(['persona'], []);

        $this->assertCount(1, $blocks);
        $this->assertSame('persona', $blocks[0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $blocks[0]['cache_control']);
    }

    public function test_to_text_passes_a_string_through(): void
    {
        $this->assertSame('plain', SystemPrompt::toText('plain'));
    }

    public function test_to_text_flattens_blocks_to_joined_text(): void
    {
        $blocks = [
            ['type' => 'text', 'text' => 'stable', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'dynamic'],
        ];

        $this->assertSame("stable\n\ndynamic", SystemPrompt::toText($blocks));
    }
}
