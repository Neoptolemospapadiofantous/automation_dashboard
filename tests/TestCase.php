<?php

namespace Tests;

use App\Billing\Plan;
use App\Http\Middleware\RequireAgent;
use App\Models\Team;
use App\Models\TeamProviderKey;
use App\Runtime\LLM\SystemPrompt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RequireAgent is meant for the production redirect-to-wizard flow.
        // In tests almost every authenticated user is created without going
        // through onboarding (factories + database fixtures), so the global
        // middleware would short-circuit nearly every Feature test before
        // it could assert anything. Tests that explicitly verify wizard
        // routing call $this->withMiddleware(RequireAgent::class) to re-enable.
        $this->withoutMiddleware(RequireAgent::class);

        // Stub the Vite manifest so view-rendering tests don't 500 when the
        // frontend hasn't been built (CI runs PHP tests without a pnpm build).
        // Tests assert Inertia props, not asset tags.
        $this->withoutVite();

        // A stub that doesn't match used to fall through to the real network:
        // a test was observed sending the fake key to api.openai.com and
        // getting a genuine 401 back. Make an unstubbed call a loud failure
        // rather than a silent (and slow, and billable) live request.
        Http::preventStrayRequests();
    }

    /**
     * Connect a provider key for a team, the only way to reach a premium
     * engine now that platform credits buy Flowstack Core alone. Moves the
     * team to Growth, the lowest plan BYOK is available on.
     */
    protected function grantOwnKey(Team $team, string $provider = 'anthropic'): void
    {
        $team->forceFill(['plan' => Plan::Growth->value])->save();

        TeamProviderKey::updateOrCreate(
            ['team_id' => $team->id, 'provider' => $provider],
            [
                'api_key' => 'sk-test-'.str_repeat('x', 20),
                'key_hint' => '…xxxx',
                'last_verified_at' => now(),
                'last_error' => null,
            ],
        );
    }

    /**
     * The system prompt out of an outbound engine request, whichever provider
     * shape it is in: Anthropic and Gemini carry it in their own field, while
     * OpenAI — which Flowstack Core runs on — sends it as the first message.
     *
     * @param  Request  $request
     */
    protected function systemTextOf($request): string
    {
        $data = $request->data();

        if (isset($data['system'])) {
            return SystemPrompt::toText($data['system']);
        }

        if (isset($data['systemInstruction'])) {
            return SystemPrompt::toText((array) ($data['systemInstruction']['parts'] ?? []));
        }

        foreach ((array) ($data['messages'] ?? []) as $message) {
            if (($message['role'] ?? '') === 'system') {
                return SystemPrompt::toText($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Stub Flowstack Core — the tier every agent runs on unless the team has
     * connected its own provider key. Mirrors the OpenAI chat-completions
     * shape the runtime parses.
     *
     * @param  list<array{text?: string, tool?: string, args?: array<string, mixed>, in?: int, out?: int}>|string  $turns
     */
    protected function fakeCore(array|string $turns = 'Hello!'): void
    {
        $turns = is_string($turns) ? [['text' => $turns]] : $turns;

        $responses = [];
        foreach ($turns as $turn) {
            $message = ['role' => 'assistant', 'content' => $turn['text'] ?? ''];
            $finish = 'stop';

            if (isset($turn['tool'])) {
                $message['content'] = $turn['text'] ?? null;
                $message['tool_calls'] = [[
                    'id' => 'call_'.substr(md5((string) $turn['tool']), 0, 8),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) $turn['tool'],
                        'arguments' => json_encode((object) ($turn['args'] ?? [])),
                    ],
                ]];
                $finish = 'tool_calls';
            }

            $responses[] = Http::response([
                'choices' => [['message' => $message, 'finish_reason' => $finish]],
                'usage' => [
                    'prompt_tokens' => $turn['in'] ?? 100,
                    'completion_tokens' => $turn['out'] ?? 50,
                ],
            ], 200);
        }

        Http::fake(['api.openai.com/v1/chat/completions' => count($responses) === 1
            ? $responses[0]
            : Http::sequence()->pushResponse(...$responses)]);
    }
}
