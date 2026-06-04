<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        protected ?string $analyticsUrl = null,
        protected ?string $realtimeUrl = null,
        protected ?string $workspaceApiKey = null,
    ) {
        $config = config('services.voiceflow');

        // Credentials default to .env config for backwards compatibility:
        // CLI commands, background jobs, and tests that don't act-as a user
        // can still get a usable client without resolving an Agent first.
        // Per-request HTTP calls flow through forAgent() instead (see below).
        $this->apiKey ??= $config['api_key'] ?? null;
        $this->environment ??= $config['environment'] ?? 'main';
        $this->projectId ??= $config['project_id'] ?? null;
        $this->runtimeUrl ??= rtrim($config['runtime_url'] ?? 'https://general-runtime.voiceflow.com', '/');
        $this->apiUrl ??= rtrim($config['api_url'] ?? 'https://api.voiceflow.com', '/');
        $this->analyticsUrl ??= rtrim($config['analytics_url'] ?? 'https://analytics-api.voiceflow.com', '/');
        $this->realtimeUrl ??= rtrim($config['realtime_url'] ?? 'https://realtime-api.voiceflow.com', '/');
        // Workspace key is used by transcripts (analytics-api) + KB CRUD
        // (realtime-api) + KB query. Falls back to the DM key for backwards
        // compatibility, but most workspace surfaces will 401 without it.
        $this->workspaceApiKey ??= $config['workspace_api_key'] ?? null;
    }

    /**
     * Build a service instance authenticated as a specific Agent.
     *
     * This is the canonical SaaS entrypoint: the container binding resolves
     * the current request's Agent via auth context and constructs through
     * here, and the per-agent webhook controller does the same after
     * route-binding {agent:slug}.
     *
     * URLs (runtime, analytics, realtime, api) still come from .env config —
     * those are infrastructure-wide and don't vary per tenant.
     */
    public static function forAgent(Agent $agent): self
    {
        // Both managed and BYOK agents now carry per-row credentials.
        // BYOK: pasted by the user in the wizard.
        // Managed: stamped from the pool by CreateAgent::createManaged.
        // The Phase J managed branch (read api_key + project_id from
        // .env config) was for an environment-clone design we abandoned
        // in Phase K — see docs/phase-13-multitenancy.md.
        return new self(
            apiKey: $agent->voiceflow_api_key,
            environment: $agent->voiceflow_environment ?: 'main',
            projectId: $agent->voiceflow_project_id,
            workspaceApiKey: $agent->voiceflow_workspace_api_key,
        );
    }

    /**
     * The credential to use for workspace surfaces (transcripts, KB CRUD,
     * KB query). Falls back to the DM key — some Voiceflow accounts let the
     * DM key talk to these surfaces too, so this keeps existing flows working
     * even when the workspace key isn't configured.
     */
    protected function workspaceKey(): ?string
    {
        return $this->workspaceApiKey ?: $this->apiKey;
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
     * @return array<int, array<string, mixed>> Parsed traces.
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
     * @return array<int, array<string, mixed>> Parsed traces.
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
     * @return array<int, array<string, mixed>> Trace objects.
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
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured.');
        }

        // Per docs/voiceflow/conversations/get-conversation-state.md the
        // canonical endpoint is GET /state/user/{userID} on the runtime host,
        // authenticated with the DM key AND a separate projectID header (no
        // environment in the path). An earlier rev of this method called a
        // fabricated /v4/.../state path that returned 404 in production.
        $response = Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'authorization' => $this->apiKey,
                'projectID' => $this->projectId,
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(15)
            ->get('/state/user/'.$this->encode($userId));

        $response->throw();

        $json = $response->json();

        // The state endpoint returns {turn, stack, variables, storage}. We
        // only care about global variables here; the stack-scoped vars are
        // intentionally ignored (those are flow-internal, not lead fields).
        $vars = $json['variables'] ?? [];

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

    // --- Transcript / Analytics API ------------------------------------------
    // Lives on a separate host (analytics-api.voiceflow.com) and is used to
    // back-fill conversations that happened in Voiceflow (preview/widget) into
    // the local store.

    /**
     * Search a project's transcripts (most recent first).
     *
     * @param  array<string, mixed>  $body  Optional filters (startDate, endDate, sessionID, ...).
     * @return array<int, array<string, mixed>> Transcript metadata rows.
     */
    public function searchTranscripts(int $take = 25, int $skip = 0, array $body = []): array
    {
        $response = $this->analyticsClient()
            ->post("/v1/transcript/project/{$this->encode($this->projectId)}?take={$take}&skip={$skip}&order=DESC", $body ?: (object) []);

        $response->throw();

        $transcripts = $response->json('transcripts');

        return is_array($transcripts) ? $transcripts : [];
    }

    /**
     * Fetch a single transcript with its logs (conversation traces).
     * Uses filterConversation so only text/speak/handoff traces come back.
     *
     * @return array<string, mixed>|null
     */
    public function getTranscript(string $transcriptId): ?array
    {
        $response = $this->analyticsClient()
            ->get("/v1/transcript/{$this->encode($transcriptId)}?filterConversation=true");

        $response->throw();

        return $response->json('transcript');
    }

    /**
     * Reduce a transcript's `logs` to a flat list of chat messages.
     *
     * Each log has `type` (trace|action|end) and `data` (the payload). User
     * input arrives as `action` (request) logs; agent output as `trace` logs
     * of type text/speak.
     *
     * @param  array<string, mixed>  $transcript
     * @return array<int, array{role: string, text: string, trace_type: string}>
     */
    public function transcriptMessages(array $transcript): array
    {
        $messages = [];

        foreach ($transcript['logs'] ?? [] as $log) {
            $type = $log['type'] ?? null;
            $data = $log['data'] ?? [];

            if ($type === 'trace') {
                $traceType = $data['type'] ?? null;
                $text = $data['payload']['message'] ?? null;
                if (in_array($traceType, ['text', 'speak'], true) && filled($text)) {
                    $messages[] = ['role' => 'agent', 'text' => (string) $text, 'trace_type' => (string) $traceType];
                }
            } elseif ($type === 'action') {
                // A user text request.
                $payload = $data['payload'] ?? null;
                $text = is_string($payload) ? $payload : ($data['payload']['query'] ?? null);
                if (($data['type'] ?? null) === 'text' && filled($text)) {
                    $messages[] = ['role' => 'user', 'text' => (string) $text, 'trace_type' => 'text'];
                }
            }
        }

        return $messages;
    }

    // --- Knowledge Base API --------------------------------------------------
    // Document management is on the realtime host; the Query endpoint is on the
    // runtime host. Both auth with the raw VF.DM key.

    /**
     * List knowledge base documents (most recently updated first).
     *
     * @param  string|null  $documentType  Filter to a single Voiceflow type:
     *   url, pdf, text, docx, md, csv, xlsx, table.
     * @return array{total: int, data: array<int, array<string, mixed>>}
     */
    public function listKbDocuments(int $page = 1, int $limit = 20, ?string $documentType = null): array
    {
        $response = $this->realtimeClient()
            ->get('/v1alpha1/public/knowledge-base/document', array_filter([
                'page' => $page,
                'limit' => $limit,
                'documentType' => $documentType,
                'projectEnvironmentIDOrAlias' => $this->environment,
            ], fn ($v) => $v !== null && $v !== ''));

        $response->throw();

        $json = $response->json();

        return [
            'total' => (int) ($json['total'] ?? 0),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
        ];
    }

    /**
     * Import a URL as a knowledge base document (scraped by Voiceflow).
     *
     * @return array<string, mixed> The created document payload.
     */
    public function createKbUrlDocument(string $url, ?string $name = null): array
    {
        $response = $this->realtimeClient()
            ->post('/v1alpha1/public/knowledge-base/document', [
                'data' => array_filter([
                    'type' => 'url',
                    'url' => $url,
                    'name' => $name,
                    'projectEnvironmentIDOrAlias' => $this->environment,
                ], fn ($v) => $v !== null),
            ]);

        $response->throw();

        return $response->json('data') ?? [];
    }

    /**
     * Upload a file as a knowledge base document. Voiceflow detects the type
     * from the filename + content (PDF / DOCX / TXT / MD / CSV / XLSX).
     * Hard cap per Voiceflow docs: 10 MB.
     *
     * Used by KnowledgeBaseController::storeFile after the controller has
     * stashed the upload to a temp path. We pass the path + intended name
     * separately so the operator-visible name stays the original upload
     * filename even if the tempfile is mangled.
     *
     * @return array<string, mixed> The created document payload.
     */
    public function createKbFileDocument(string $filePath, string $name): array
    {
        $response = $this->realtimeClient()
            ->asMultipart()
            ->attach('file', file_get_contents($filePath), $name)
            ->post('/v1alpha1/public/knowledge-base/document', array_filter([
                'projectEnvironmentIDOrAlias' => $this->environment,
            ], fn ($v) => $v !== null && $v !== ''));

        $response->throw();

        return $response->json('data') ?? [];
    }

    /**
     * Fetch one document including its chunks and metadata. Used by the
     * KB UI's "view" affordance so users can inspect what was actually
     * scraped/parsed before trusting the answer the agent gives.
     *
     * @return array{data: array<string, mixed>, chunks: array<int, array<string, mixed>>, metadata: array<int, array<string, mixed>>}
     */
    public function getKbDocument(string $documentID): array
    {
        $response = $this->realtimeClient()
            ->get('/v1alpha1/public/knowledge-base/document/'.urlencode($documentID), array_filter([
                'projectEnvironmentIDOrAlias' => $this->environment,
            ], fn ($v) => $v !== null && $v !== ''));

        $response->throw();

        $json = $response->json();

        return [
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
            'chunks' => is_array($json['chunks'] ?? null) ? $json['chunks'] : [],
            'metadata' => is_array($json['metadata'] ?? null) ? $json['metadata'] : [],
        ];
    }

    /**
     * Delete a document from the agent's KB.
     */
    public function deleteKbDocument(string $documentID): void
    {
        $response = $this->realtimeClient()
            ->delete('/v1alpha1/public/knowledge-base/document/'.urlencode($documentID), array_filter([
                'projectEnvironmentIDOrAlias' => $this->environment,
            ], fn ($v) => $v !== null && $v !== ''));

        $response->throw();
    }

    /**
     * Query the knowledge base — returns a synthesized answer + source chunks.
     *
     * @return array{answer: ?string, chunks: array<int, array<string, mixed>>}
     */
    public function queryKnowledgeBase(string $question, int $chunkLimit = 5, bool $synthesis = true): array
    {
        // KB query is a workspace-key surface (per docs/voiceflow/
        // knowledge-base/query.md). Earlier code passed the DM key here via
        // the default runtime client — that's the wrong scope and 401s for
        // tenants that have set a workspace key explicitly. Headers built
        // once in one withHeaders() call because Laravel's withHeaders
        // *merges* rather than replacing — a second call doesn't override.
        $response = Http::baseUrl($this->runtimeUrl)
            ->withHeaders([
                'authorization' => $this->workspaceKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(30)
            ->post('/knowledge-base/query', [
                'projectID' => $this->projectId,
                'question' => $question,
                'chunkLimit' => $chunkLimit,
                'synthesis' => $synthesis,
                'projectEnvironmentIDOrAlias' => $this->environment,
            ]);

        $response->throw();

        $json = $response->json();

        return [
            'answer' => $json['output'] ?? null,
            'chunks' => array_map(fn ($c) => [
                'content' => $c['content'] ?? '',
                'score' => $c['score'] ?? null,
                'documentID' => $c['documentID'] ?? null,
                'source' => $c['source']['name'] ?? null,
            ], is_array($json['chunks'] ?? null) ? $json['chunks'] : []),
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
     * HTTP client for the Realtime API (KB document management; separate host).
     *
     * Workspace-key surface — the DM key authenticates ✗ here for most
     * accounts. Falls back to the DM key for backwards compatibility but
     * the tenant should configure voiceflow_workspace_api_key for KB ops.
     */
    protected function realtimeClient(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured.');
        }

        return Http::baseUrl($this->realtimeUrl)
            ->withHeaders([
                'authorization' => $this->workspaceKey(),
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(30);
    }

    /**
     * HTTP client for the Analytics/Transcript API (separate host).
     *
     * Workspace-key surface (transcripts, transcript properties, evaluations,
     * usage). The DM key will 401 here for most tenants. Falls back to the
     * DM key but the tenant should configure voiceflow_workspace_api_key.
     */
    protected function analyticsClient(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Voiceflow is not configured.');
        }

        return Http::baseUrl($this->analyticsUrl)
            ->withHeaders([
                'authorization' => $this->workspaceKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->connectTimeout(5)
            ->timeout(20);
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
    protected function startSessionResponse(string $userId): Response
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
            ->retry(2, 200, when: fn ($e) => $e instanceof ConnectionException, throw: false)
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
