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
        protected BridgeClient $bridge,
    ) {}

    public function clientFor(string $provider, ?string $apiKey = null): LlmClient
    {
        // Bring-your-own-key BYPASSES THE BRIDGE deliberately. The bridge is
        // subscription auth on OUR account; handing a customer's traffic to it
        // would bill us and, worse, run their chat on a personal subscription.
        // A supplied key always means a direct, metered call on that key.
        if ($apiKey !== null && $apiKey !== '') {
            return match ($provider) {
                'anthropic' => $this->anthropic->withApiKey($apiKey),
                'openai' => $this->openai->withApiKey($apiKey),
                'google' => $this->gemini->withApiKey($apiKey),
                default => throw new Misconfigured("Provider '{$provider}' does not support a customer-supplied key."),
            };
        }

        return match ($provider) {
            // Bridge-first: with claude-bridge enabled, every Anthropic tier
            // runs on subscription auth and the metered API is never called.
            'anthropic' => self::bridgeEnabled() ? $this->bridge : $this->anthropic,
            'openai' => $this->openai,
            'google' => $this->gemini,
            default => throw new Misconfigured("Unknown LLM provider '{$provider}' — check runtime.tiers config."),
        };
    }

    /**
     * Whether a provider's API key is configured — drives tier
     * availability in the UI and tier validation. A configured bridge
     * makes the anthropic provider available without any API key.
     */
    public static function providerAvailable(string $provider): bool
    {
        return match ($provider) {
            'anthropic' => self::bridgeEnabled()
                || (string) config('runtime.llm.anthropic.api_key') !== '',
            'openai' => (string) config('runtime.llm.openai.api_key') !== '',
            'google' => (string) config('runtime.llm.google.api_key') !== '',
            default => false,
        };
    }

    public static function bridgeEnabled(): bool
    {
        return (bool) config('runtime.llm.bridge.enabled')
            && (string) config('runtime.llm.bridge.url') !== '';
    }
}
