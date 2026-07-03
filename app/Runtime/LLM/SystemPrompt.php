<?php

namespace App\Runtime\LLM;

/**
 * The system prompt in its canonical (Anthropic-shaped) form: a list of
 * text blocks, where a block may carry `cache_control` to mark the end of
 * a cacheable prefix. This is the same "canonical = Anthropic-shaped"
 * convention the message format already follows (see LlmClient).
 *
 * FlowExecutor builds blocks so the STABLE content (persona, operator
 * instructions, automation catalog) leads and carries the cache
 * breakpoint, and the per-turn DYNAMIC content (objective, visitor facts,
 * KB context) follows uncached. AnthropicClient sends the blocks verbatim
 * so Anthropic can cache the stable prefix (~90% cheaper input on every
 * turn after the first). Providers without explicit caching flatten the
 * blocks back to a plain string with toText().
 */
final class SystemPrompt
{
    /**
     * Wrap a stable (cacheable) prefix and a dynamic (per-turn) suffix into
     * canonical system blocks. Empty segments are dropped; the cache
     * breakpoint sits on the last stable block. Returns a plain string when
     * there is nothing worth caching (no stable content), so short prompts
     * never pay the cache-write premium.
     *
     * @param  list<string>  $stable  Content identical across a conversation.
     * @param  list<string>  $dynamic  Content that changes per turn.
     * @return string|list<array<string, mixed>>
     */
    public static function blocks(array $stable, array $dynamic): string|array
    {
        $stableText = trim(implode("\n\n", array_filter($stable, fn ($s) => trim((string) $s) !== '')));
        $dynamicText = trim(implode("\n\n", array_filter($dynamic, fn ($s) => trim((string) $s) !== '')));

        if ($stableText === '') {
            return $dynamicText;
        }

        $blocks = [[
            'type' => 'text',
            'text' => $stableText,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
        if ($dynamicText !== '') {
            $blocks[] = ['type' => 'text', 'text' => $dynamicText];
        }

        return $blocks;
    }

    /**
     * Flatten a system prompt (string or canonical blocks) to plain text —
     * for providers whose wire format takes a single system string.
     *
     * @param  string|array<int, array<string, mixed>>  $system
     */
    public static function toText(string|array $system): string
    {
        if (is_string($system)) {
            return $system;
        }

        return implode("\n\n", array_map(
            fn (array $block): string => (string) ($block['text'] ?? ''),
            $system,
        ));
    }
}
