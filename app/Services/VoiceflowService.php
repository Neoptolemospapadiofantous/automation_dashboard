<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Server-side client for the Voiceflow Dialog Manager API.
 *
 * The Dialog Manager (a.k.a. Conversations) API drives a Voiceflow agent over
 * HTTP: you POST an "action" (launch / text) for a given user id and receive
 * back an array of "trace" objects to render. Session variables (the lead
 * fields the agent captures) are read/written via the variables endpoint.
 *
 * The API key (prefix VF.DM.*) is read from config and never leaves the server.
 *
 * @see https://docs.voiceflow.com/reference/stateinteract-1
 * @see https://docs.voiceflow.com/reference/trace-types
 */
class VoiceflowService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $versionId = null,
        protected ?string $projectId = null,
        protected ?string $runtimeUrl = null,
        protected ?string $apiUrl = null,
    ) {
        $config = config('services.voiceflow');

        $this->apiKey ??= $config['api_key'] ?? null;
        $this->versionId ??= $config['version_id'] ?? 'production';
        $this->projectId ??= $config['project_id'] ?? null;
        $this->runtimeUrl ??= rtrim($config['runtime_url'] ?? 'https://general-runtime.voiceflow.com', '/');
        $this->apiUrl ??= rtrim($config['api_url'] ?? 'https://api.voiceflow.com', '/');
    }

    /**
     * Whether the integration is configured (has an API key).
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Start (or reset) a conversation for a user.
     *
     * @param  array<string, mixed>  $variables  Optional variables to pre-fill.
     * @return array<int, array<string, mixed>>  Parsed traces.
     */
    public function launch(string $userId, array $variables = []): array
    {
        return $this->interact($userId, ['type' => 'launch'], $variables);
    }

    /**
     * Diagnose the integration: configured? reachable? key valid? version found?
     * Probes both the configured version and the common alternatives so a
     * publish/version mismatch is obvious.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'configured' => false, 'reason' => 'VOICEFLOW_API_KEY is not set.'];
        }

        $keyPrefix = substr((string) $this->apiKey, 0, 6);

        // Probe the configured version plus development/production so a
        // "works on draft but not published" situation is immediately clear.
        $candidates = array_values(array_unique([$this->versionId, 'development', 'production']));
        $probes = [];
        $anyOk = false;
        $okVersion = null;

        foreach ($candidates as $version) {
            $probe = $this->probeVersion($version);
            $probes[$version] = $probe;
            if ($probe['ok']) {
                $anyOk = true;
                $okVersion ??= $version;
            }
        }

        return [
            'ok' => $anyOk,
            'configured' => true,
            'key_prefix' => $keyPrefix,
            'looks_like_dm_key' => str_starts_with((string) $this->apiKey, 'VF.DM.'),
            'configured_version_id' => $this->versionId,
            'working_version_id' => $okVersion,
            'probes' => $probes,
            'reason' => $this->summarize($anyOk, $okVersion, $probes),
        ];
    }

    /**
     * Probe a single version id with a launch and report the outcome.
     *
     * @return array{ok: bool, status: int|null, body: string|null}
     */
    protected function probeVersion(string $versionId): array
    {
        try {
            $response = Http::baseUrl($this->runtimeUrl)
                ->withHeaders([
                    'Authorization' => $this->apiKey,
                    'versionID' => $versionId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->post('/state/user/healthcheck-'.uniqid().'/interact', [
                    'action' => ['type' => 'launch'],
                ]);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->successful() ? null : Str::limit((string) $response->body(), 200),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => Str::limit($e->getMessage(), 200)];
        }
    }

    /**
     * @param  array<string, array{ok: bool, status: int|null, body: string|null}>  $probes
     */
    protected function summarize(bool $anyOk, ?string $okVersion, array $probes): string
    {
        if ($anyOk) {
            if ($okVersion !== $this->versionId) {
                return "Working on '{$okVersion}' but not '{$this->versionId}'. Set VOICEFLOW_VERSION_ID={$okVersion}, or publish the agent to production.";
            }

            return 'Voiceflow responded OK.';
        }

        $statuses = array_map(fn ($p) => $p['status'], $probes);

        if (in_array(401, $statuses, true) || in_array(403, $statuses, true)) {
            return 'Key rejected — check it is a VF.DM.* Dialog Manager key for THIS project.';
        }

        if (in_array(404, $statuses, true)) {
            return 'Version not found — publish/run the agent, or fix VOICEFLOW_VERSION_ID.';
        }

        if (in_array(500, $statuses, true)) {
            return 'Voiceflow returns 500 on launch for every version. The agent itself is erroring — open it in Voiceflow and click Run/Test. A brand-new Agentic agent must be built and published before the API can run it.';
        }

        return 'Could not get a successful response from Voiceflow on any version.';
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
     * Low-level interact call.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $variables
     * @return array<int, array<string, mixed>>  Trace objects.
     */
    public function interact(string $userId, array $action, array $variables = []): array
    {
        $body = [
            'action' => $action,
            'config' => [
                'tts' => false,
                'stripSSML' => true,
                'stopAll' => true,
                'excludeTypes' => ['block', 'debug', 'flow'],
            ],
        ];

        if ($variables !== []) {
            $body['state'] = ['variables' => $variables];
        }

        $response = $this->client()
            ->post("/state/user/{$this->encode($userId)}/interact", $body);

        $response->throw();

        /** @var array<int, array<string, mixed>> $traces */
        $traces = $response->json();

        return is_array($traces) ? $traces : [];
    }

    /**
     * Read the agent's session variables for a user (the captured lead fields).
     *
     * @return array<string, mixed>
     */
    public function getVariables(string $userId): array
    {
        $response = $this->client()
            ->get("/state/user/{$this->encode($userId)}/variables");

        $response->throw();

        $vars = $response->json();

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
     * A configured HTTP client pointed at the runtime, with auth headers.
     */
    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured: set VOICEFLOW_API_KEY.');
        }

        return Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'Authorization' => $this->apiKey,
                'versionID' => $this->versionId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->timeout(20)
            ->retry(2, 200);
    }

    protected function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
