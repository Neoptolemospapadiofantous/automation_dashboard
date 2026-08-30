<?php

namespace App\Console\Commands;

use App\Runtime\LLM\LlmRouter;
use App\Support\Findings\FindingsStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Provider liveness tripwire. The Stripe credit ledger (revenue) and the
 * provider API balances (cost) are SEPARATE ledgers — a funded customer
 * can still hit a dead provider. This pings each provider with a 1-token
 * request and flags empty/throttled balances BEFORE a customer does.
 *
 * Detection (live state — money runs out without warning):
 *   - Anthropic  POST /v1/messages         400 "credit balance too low" = empty
 *   - OpenAI     POST /v1/embeddings       429 insufficient_quota       = empty
 *                (also kills RAG: every agent's KB retrieval needs embeddings)
 *   - Gemini     POST :generateContent     429 free_tier RESOURCE_EXHAUSTED
 *                = free-tier/throttled (demo-only, not production-viable)
 *
 * Writes data/agents/provider-health/findings.json in the same schema the
 * system-check agent uses. The grid tooling reads that tree (grid-control :8093,
 * grid-sentinel, grid-live) and grid-notify delivers FAIL/WARN to Telegram; the
 * hermes-slack consumer was retired 2026-08-27. Nothing is delivered from this repo.
 */
class ProviderHealthCheck extends Command
{
    protected $signature = 'providers:health-check';

    protected $description = 'Ping Anthropic/OpenAI/Gemini liveness and flag empty/throttled providers.';

    public function handle(): int
    {
        $checks = [
            $this->checkAnthropic(),
            $this->checkOpenAi(),
            $this->checkGemini(),
        ];

        $pass = $warn = $fail = 0;
        foreach ($checks as $c) {
            match ($c['status']) {
                'PASS' => $pass++,
                'WARN' => $warn++,
                default => $fail++,
            };
        }

        $overall = $fail > 0 ? 'FAIL' : ($warn > 0 ? 'WARN' : 'PASS');
        $ts = now()->utc()->toIso8601ZuluString();

        $this->writeFindings($overall, $pass, $warn, $fail, $checks, $ts);

        foreach ($checks as $c) {
            $line = sprintf('%s — %s: %s', $c['status'], $c['check'], $c['detail']);
            match ($c['status']) {
                'PASS' => $this->components->info($line),
                'WARN' => $this->components->warn($line),
                default => $this->components->error($line),
            };
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    protected function checkAnthropic(): array
    {
        // Bridge-first (2026-07-31): anthropic tiers run on claude-bridge
        // subscription auth — probe the bridge, never the metered API.
        if (LlmRouter::bridgeEnabled()) {
            $url = rtrim((string) config('runtime.llm.bridge.url'), '/');
            try {
                $res = Http::timeout(10)->get($url.'/health');
            } catch (\Throwable $e) {
                return $this->finding('provider-anthropic', 'FAIL', 'claude-bridge unreachable ('.$e->getMessage().') — anthropic tiers DEAD; check the bridge service/tunnel');
            }
            if ($res->successful() && $res->json('ok') === true) {
                return $this->finding('provider-anthropic', 'PASS', 'claude-bridge healthy (subscription auth, model '.(string) $res->json('model').') — no metered API in use');
            }

            return $this->finding('provider-anthropic', 'FAIL', 'claude-bridge /health returned HTTP '.$res->status().' — anthropic tiers DEAD');
        }

        $key = (string) config('runtime.llm.anthropic.api_key');
        $base = rtrim((string) config('runtime.llm.anthropic.base_url'), '/');
        $model = (string) config('runtime.llm.anthropic.model_default');

        if ($key === '') {
            // Deliberate state, not an incident (2026-08-10 metered flip):
            // without a key the Claude tiers show as "Coming soon" in the
            // tier picker and agents degrade to the funded default tier.
            // WARN keeps the hourly findings honest without burying real
            // FAILs under expected noise.
            return $this->finding('provider-anthropic', 'WARN', 'ANTHROPIC_API_KEY not set — Claude tiers shown as coming soon; agents degrade to the default tier');
        }

        try {
            $res = Http::timeout(15)
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->post($base.'/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);
        } catch (\Throwable $e) {
            return $this->finding('provider-anthropic', 'WARN', 'unreachable: '.$e->getMessage());
        }

        $body = strtolower($res->body());
        if ($res->status() === 400 && str_contains($body, 'credit balance is too low')) {
            return $this->finding('provider-anthropic', 'FAIL', 'credit balance too low — default (Haiku) chat is DEAD; fund Anthropic');
        }
        if ($res->status() === 401 || $res->status() === 403) {
            return $this->finding('provider-anthropic', 'FAIL', 'auth rejected (HTTP '.$res->status().') — key invalid/revoked');
        }
        if ($res->successful()) {
            return $this->finding('provider-anthropic', 'PASS', 'funded — '.$model.' answered a 1-token ping');
        }

        return $this->finding('provider-anthropic', 'WARN', 'unexpected HTTP '.$res->status().': '.substr($res->body(), 0, 160));
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    protected function checkOpenAi(): array
    {
        $key = (string) config('runtime.embeddings.openai_api_key');
        $base = rtrim((string) config('runtime.embeddings.openai_base_url'), '/');
        $model = (string) config('runtime.embeddings.model');

        if ($key === '') {
            return $this->finding('provider-openai', 'FAIL', 'OPENAI_API_KEY is not set — embeddings/RAG cannot run');
        }

        try {
            $res = Http::timeout(15)
                ->withToken($key)
                ->post($base.'/v1/embeddings', [
                    'model' => $model,
                    'input' => 'ping',
                ]);
        } catch (\Throwable $e) {
            return $this->finding('provider-openai', 'WARN', 'unreachable: '.$e->getMessage());
        }

        $body = strtolower($res->body());
        if ($res->status() === 429 && str_contains($body, 'insufficient_quota')) {
            return $this->finding('provider-openai', 'FAIL', 'insufficient_quota — embeddings/RAG DEAD for every agent; OpenAI funding is non-negotiable');
        }
        if ($res->status() === 401 || $res->status() === 403) {
            return $this->finding('provider-openai', 'FAIL', 'auth rejected (HTTP '.$res->status().') — key invalid/revoked');
        }
        if ($res->successful()) {
            return $this->finding('provider-openai', 'PASS', 'funded — '.$model.' embeddings reachable');
        }
        if ($res->status() === 429) {
            return $this->finding('provider-openai', 'WARN', 'rate-limited (429, not insufficient_quota) — transient');
        }

        return $this->finding('provider-openai', 'WARN', 'unexpected HTTP '.$res->status().': '.substr($res->body(), 0, 160));
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    protected function checkGemini(): array
    {
        $key = (string) config('runtime.llm.google.api_key');
        $base = rtrim((string) config('runtime.llm.google.base_url'), '/');
        $model = (string) config('runtime.llm.google.model_default');

        if ($key === '') {
            return $this->finding('provider-gemini', 'WARN', 'GEMINI_API_KEY is not set');
        }

        try {
            $res = Http::timeout(15)
                ->post($base.'/v1beta/models/'.$model.':generateContent?key='.urlencode($key), [
                    'contents' => [['parts' => [['text' => 'ping']]]],
                    'generationConfig' => ['maxOutputTokens' => 1],
                ]);
        } catch (\Throwable $e) {
            return $this->finding('provider-gemini', 'WARN', 'unreachable: '.$e->getMessage());
        }

        $body = strtolower($res->body());
        if ($res->status() === 429 && str_contains($body, 'free_tier')) {
            return $this->finding('provider-gemini', 'WARN', 'free-tier quota (RESOURCE_EXHAUSTED) — demo-only, not production-viable; move to a paid Cloud-billing project');
        }
        if ($res->status() === 429) {
            return $this->finding('provider-gemini', 'WARN', 'rate-limited (429) — throttled');
        }
        if ($res->status() === 401 || $res->status() === 403) {
            return $this->finding('provider-gemini', 'FAIL', 'auth rejected (HTTP '.$res->status().') — key invalid/revoked');
        }
        if ($res->successful()) {
            // A 200 does NOT prove a paid tier — free tier also answers until
            // it throttles. Treat as PASS but say so, so we don't over-trust it.
            return $this->finding('provider-gemini', 'PASS', 'reachable — '.$model.' answered (tier unverified; 429 free_tier under load = still free)');
        }

        return $this->finding('provider-gemini', 'WARN', 'unexpected HTTP '.$res->status().': '.substr($res->body(), 0, 160));
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    protected function finding(string $check, string $status, string $detail): array
    {
        return ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param  array<int, array{check: string, status: string, detail: string}>  $checks
     */
    protected function writeFindings(string $overall, int $pass, int $warn, int $fail, array $checks, string $ts): void
    {
        $dir = base_path('data/agents/provider-health');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $findings = [
            'ts' => $ts,
            'overall' => $overall,
            'pass' => $pass,
            'warn' => $warn,
            'fail' => $fail,
            'checks' => $checks,
        ];
        file_put_contents(
            $dir.'/findings.json',
            json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        // The DB is the durable record — the file above is inside the release
        // dir on Forge and does not survive a deploy. Never fail the check on it.
        try {
            app(FindingsStore::class)->record('provider-health', $findings);
        } catch (\Throwable $e) {
            report($e);
        }

        file_put_contents(
            $dir.'/last_run.json',
            json_encode([
                'agent' => 'provider-health',
                'state' => 'idle',
                'ts' => $ts,
                'summary' => $overall,
            ], JSON_PRETTY_PRINT)."\n"
        );
    }
}
