<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Server-side client for the Voiceflow V4 Conversations API.
 *
 * V4 uses a two-step flow keyed on projectID + environment (alias "main"):
 *   1. POST /v4/project/{projectID}/environment/{environmentID}/session
 *      with the VF.DM API key -> returns a `sessionKey`.
 *   2. POST /v4/interact with the `sessionKey` as the authorization header
 *      -> returns `{ "traces": [...] }`.
 *
 * The API key (prefix VF.DM.*) is read from config and never leaves the server.
 * Per-user session keys are cached so a conversation continues across turns.
 *
 * @see https://docs.voiceflow.com/api-reference/session/start-session-specific-environment
 * @see https://docs.voiceflow.com/api-reference/conversation/interact-non-stream
 */
class VoiceflowService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $environment = null,
        protected ?string $projectId = null,
        protected ?string $runtimeUrl = null,
        protected ?string $apiUrl = null,
    ) {
        $config = config('services.voiceflow');

        $this->apiKey ??= $config['api_key'] ?? null;
        $this->environment ??= $config['environment'] ?? 'main';
        $this->projectId ??= $config['project_id'] ?? null;
        $this->runtimeUrl ??= rtrim($config['runtime_url'] ?? 'https://general-runtime.voiceflow.com', '/');
        $this->apiUrl ??= rtrim($config['api_url'] ?? 'https://api.voiceflow.com', '/');
    }

    /**
     * Whether the integration is configured (API key + project id).
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->projectId);
    }

    /**
     * Start (or reset) a conversation for a user.
     *
     * @param  array<string, mixed>  $variables  Optional variables to pre-fill.
     * @return array<int, array<string, mixed>>  Parsed traces.
     */
    public function launch(string $userId, array $variables = []): array
    {
        // A launch starts a fresh session; drop any cached session for this user.
        $this->forgetSession($userId);

        return $this->interact($userId, ['type' => 'launch'], $variables);
    }

    /**
     * Send a user's text reply and advance the conversation.
     *
     * @return array<int, array<string, mixed>>  Parsed traces.
     */
    public function sendText(string $userId, string $text): array
    {
        return $this->interact($userId, ['type' => 'text', 'payload' => $text]);
    }

    /**
     * Low-level interact call: ensures a session, then POSTs the action.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $variables
     * @return array<int, array<string, mixed>>  Trace objects.
     */
    public function interact(string $userId, array $action, array $variables = []): array
    {
        $sessionKey = $this->sessionKey($userId);

        $body = ['action' => $action];
        if ($variables !== []) {
            $body['variables'] = $variables;
        }

        $response = Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'authorization' => $sessionKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(20)
            ->post('/v4/interact', $body);

        $response->throw();

        $json = $response->json();

        /** @var array<int, array<string, mixed>> $traces */
        $traces = $json['traces'] ?? [];

        return is_array($traces) ? $traces : [];
    }

    /**
     * Read the agent's session variables for a user (the captured lead fields).
     *
     * @return array<string, mixed>
     */
    public function getVariables(string $userId): array
    {
        // V4 state lookup is keyed on projectID + environment + userID.
        $response = $this->apiClient()
            ->get($this->statePath($userId));

        $response->throw();

        $json = $response->json();

        // The state endpoint returns the full conversation state; variables
        // live under `variables` (fall back to the raw payload for safety).
        $vars = $json['variables'] ?? $json;

        return is_array($vars) ? $vars : [];
    }

    /**
     * Pick out just the lead-relevant variables, dropping empty values.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function extractLeadFields(array $variables): array
    {
        $keys = config('services.voiceflow.lead_variables', []);

        $fields = [];
        foreach ($keys as $key) {
            $value = $variables[$key] ?? null;
            if ($value !== null && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Reduce raw traces to the chat messages + choice buttons a UI needs.
     *
     * @param  array<int, array<string, mixed>>  $traces
     * @return array{messages: array<int, string>, buttons: array<int, array{name: string, request: mixed}>, ended: bool}
     */
    public function parseTraces(array $traces): array
    {
        $messages = [];
        $buttons = [];
        $ended = false;

        foreach ($traces as $trace) {
            $type = $trace['type'] ?? null;
            $payload = $trace['payload'] ?? [];

            match ($type) {
                'text', 'speak' => $messages[] = (string) ($payload['message'] ?? ''),
                'choice' => $buttons = array_merge($buttons, $this->normalizeButtons($payload['buttons'] ?? [])),
                'end' => $ended = true,
                default => null,
            };
        }

        return [
            'messages' => array_values(array_filter($messages, fn ($m) => $m !== '')),
            'buttons' => $buttons,
            'ended' => $ended,
        ];
    }

    /**
     * Diagnose the integration end to end: configured? can we start a session?
     * can we launch? Surfaces the precise failing step.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        if (empty($this->apiKey)) {
            return ['ok' => false, 'configured' => false, 'reason' => 'VOICEFLOW_API_KEY is not set.'];
        }

        if (empty($this->projectId)) {
            return ['ok' => false, 'configured' => false, 'reason' => 'VOICEFLOW_PROJECT_ID is not set (required for the V4 API).'];
        }

        $base = [
            'configured' => true,
            'key_prefix' => substr((string) $this->apiKey, 0, 6),
            'looks_like_dm_key' => str_starts_with((string) $this->apiKey, 'VF.DM.'),
            'project_id' => $this->projectId,
            'environment' => $this->environment,
        ];

        // Step 1: start a session.
        try {
            $session = $this->startSessionResponse('healthcheck-'.uniqid());
        } catch (\Throwable $e) {
            return [...$base, 'ok' => false, 'step' => 'start_session', 'reason' => 'Could not reach Voiceflow: '.Str::limit($e->getMessage(), 200)];
        }

        if (! $session->successful()) {
            return [...$base, 'ok' => false, 'step' => 'start_session', 'upstream_status' => $session->status(),
                'reason' => $this->sessionFailureReason($session->status()), 'body' => Str::limit((string) $session->body(), 200)];
        }

        $key = $session->json('sessionKey');
        if (! $key) {
            return [...$base, 'ok' => false, 'step' => 'start_session', 'reason' => 'Session started but no sessionKey was returned.'];
        }

        // Step 2: launch.
        try {
            $interact = Http::baseUrl($this->runtimeUrl)
                ->withHeaders(['authorization' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->connectTimeout(5)->timeout(15)
                ->post('/v4/interact', ['action' => ['type' => 'launch']]);
        } catch (\Throwable $e) {
            return [...$base, 'ok' => false, 'step' => 'interact', 'reason' => 'Could not reach Voiceflow: '.Str::limit($e->getMessage(), 200)];
        }

        if ($interact->successful()) {
            return [...$base, 'ok' => true, 'reason' => 'Voiceflow responded OK (session + launch succeeded).'];
        }

        return [...$base, 'ok' => false, 'step' => 'interact', 'upstream_status' => $interact->status(),
            'reason' => 'Session started but launch failed (HTTP '.$interact->status().'). The agent may be erroring on launch.',
            'body' => Str::limit((string) $interact->body(), 200)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<int, array{name: string, request: mixed}>
     */
    protected function normalizeButtons(array $buttons): array
    {
        return array_map(fn ($b) => [
            'name' => (string) ($b['name'] ?? ''),
            'request' => $b['request'] ?? null,
        ], $buttons);
    }

    /**
     * Get a cached session key for a user, starting a new session if needed.
     */
    protected function sessionKey(string $userId): string
    {
        $cacheKey = $this->sessionCacheKey($userId);

        $key = Cache::get($cacheKey);
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $response = $this->startSessionResponse($userId);
        $response->throw();

        $key = $response->json('sessionKey');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Voiceflow start-session did not return a sessionKey.');
        }

        // Sessions are long-lived; cache for an hour so multi-turn chats reuse it.
        Cache::put($cacheKey, $key, now()->addHour());

        return $key;
    }

    /**
     * POST the start-session endpoint and return the raw response.
     */
    protected function startSessionResponse(string $userId): \Illuminate\Http\Client\Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured: set VOICEFLOW_API_KEY and VOICEFLOW_PROJECT_ID.');
        }

        return Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 200, when: fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
            ->post(sprintf(
                '/v4/project/%s/environment/%s/session',
                $this->encode($this->projectId),
                $this->encode($this->environment),
            ), ['userID' => $userId]);
    }

    protected function forgetSession(string $userId): void
    {
        Cache::forget($this->sessionCacheKey($userId));
    }

    protected function sessionCacheKey(string $userId): string
    {
        return 'vf_session:'.$this->projectId.':'.$this->environment.':'.$userId;
    }

    /**
     * Client authed with the raw API key (for state/variables endpoints).
     */
    protected function apiClient(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured.');
        }

        return Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(15);
    }

    protected function statePath(string $userId): string
    {
        return sprintf(
            '/v4/project/%s/environment/%s/user/%s/state',
            $this->encode($this->projectId),
            $this->encode($this->environment),
            $this->encode($userId),
        );
    }

    protected function sessionFailureReason(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'Key rejected — check VOICEFLOW_API_KEY is a VF.DM.* key for THIS project.',
            $status === 404 => 'Project or environment not found — check VOICEFLOW_PROJECT_ID and VOICEFLOW_ENVIRONMENT (alias, e.g. "main").',
            default => 'Start-session failed (HTTP '.$status.').',
        };
    }

    protected function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
