<?php

namespace App\Runtime\LLM;

use App\Runtime\Exceptions\Misconfigured;

/**
 * Resolves the LlmClient for a tier's provider. The FlowExecutor asks
 * once per turn; clients are stateless singletons.
 */
class LlmRouter
{
    public function __construct(
        protected AnthropicClient $anthropic,
        protected OpenAiClient $openai,
        protected GeminiClient $gemini,
    ) {}

    public function clientFor(string $provider): LlmClient
    {
        return match ($provider) {
            'anthropic' => $this->anthropic,
            'openai' => $this->openai,
            'google' => $this->gemini,
            default => throw new Misconfigured("Unknown LLM provider '{$provider}' — check runtime.tiers config."),
        };
    }

    /**
     * Whether a provider's API key is configured — drives tier
     * availability in the UI and tier validation.
     */
    public static function providerAvailable(string $provider): bool
    {
        return match ($provider) {
            'anthropic' => (string) config('runtime.llm.anthropic.api_key') !== '',
            'openai' => (string) config('runtime.llm.openai.api_key') !== '',
            'google' => (string) config('runtime.llm.google.api_key') !== '',
            default => false,
        };
    }
}
