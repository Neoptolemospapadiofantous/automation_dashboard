<?php

namespace App\Services\Voiceflow\Client;

use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Wraps the Voiceflow `general-runtime` host:
 *   - start-session (returns sessionKey)
 *   - /v4/interact (run a turn)
 *   - /state/user/{userID} (CRUD)
 *   - /knowledge-base/query
 *
 * Uses the Dialog Manager key (`VOICEFLOW_API_KEY`) for session + state;
 * uses the workspace key for KB query (Voiceflow routes the workspace key
 * even though the host is the runtime).
 */
class RuntimeClient
{
    /** Cache TTL for sessionKey in seconds (1 hour matches Voiceflow's window). */
    public const SESSION_TTL_SECONDS = 3600;

    public function __construct(
        protected VoiceflowHttpClient $http,
        protected string $baseUrl,
        protected string $apiKey,
        protected string $projectId,
        protected string $environment,
        protected ?string $workspaceApiKey = null,
    ) {}

    /**
     * Start a Voiceflow session for the given user; caches the returned sessionKey.
     *
     * @return non-empty-string The sessionKey.
     */
    public function sessionKey(string $userId): string
    {
        $cacheKey = $this->sessionCacheKey($userId);

        return Cache::remember($cacheKey, self::SESSION_TTL_SECONDS, function () use ($userId): string {
            $endpoint = sprintf(
                '/v4/project/%s/environment/%s/session',
                rawurlencode($this->projectId),
                rawurlencode($this->environment),
            );

            $response = $this->http
                ->client($this->baseUrl, $this->apiKey, timeout: 15)
                ->post($endpoint, ['userID' => $userId]);

            $response = $this->http->ensureOk($response, 'runtime.session.start', [
                'user_id' => $userId,
                'project_id' => $this->projectId,
            ]);

            $body = $response->json();
            $sessionKey = is_array($body) ? ($body['sessionKey'] ?? null) : null;

            if (! is_string($sessionKey) || $sessionKey === '') {
                throw new MisconfiguredException(
                    'Voiceflow start-session returned no sessionKey',
                    httpStatus: 200,
                    endpoint: 'runtime.session.start',
                );
            }

            return $sessionKey;
        });
    }

    /**
     * Run one turn against the runtime.
     *
     * @param  array{type:non-empty-string, payload?: array<string,mixed>}  $action
     * @param  array<string,mixed>  $variables
     * @return array<int, array<string,mixed>> raw traces
     */
    public function interact(string $userId, array $action, array $variables = []): array
    {
        $sessionKey = $this->sessionKey($userId);

        $response = $this->http
            ->client($this->baseUrl, $sessionKey, timeout: 20)
            ->post('/v4/interact', [
                'action' => $action,
                'state' => ['variables' => (object) $variables],
            ]);

        // Self-heal stale session keys: VF can expire/invalidate without notice.
        if ($response->status() === 401) {
            $this->forgetSession($userId);
            $sessionKey = $this->sessionKey($userId);

            $response = $this->http
                ->client($this->baseUrl, $sessionKey, timeout: 20)
                ->post('/v4/interact', [
                    'action' => $action,
                    'state' => ['variables' => (object) $variables],
                ]);
        }

        $response = $this->http->ensureOk($response, 'runtime.interact', [
            'user_id' => $userId,
            'action_type' => $action['type'] ?? null,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Fetch the user's current state. Returns the full state object
     * (`variables`, `turn`, `stack`, `storage`) unlike the lossy variable-only
     * reduction the legacy service did.
     *
     * @return array<string,mixed>
     */
    public function getState(string $userId): array
    {
        $endpoint = sprintf('/state/user/%s', rawurlencode($userId));

        $response = $this->http
            ->client($this->baseUrl, $this->apiKey, timeout: 15, extraHeaders: ['projectID' => $this->projectId])
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'runtime.state.get', [
            'user_id' => $userId,
            'project_id' => $this->projectId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Get just the user's variables map (convenience for the lead-capture path).
     *
     * @return array<string,mixed>
     */
    public function getVariables(string $userId): array
    {
        $state = $this->getState($userId);
        $vars = $state['variables'] ?? null;

        return is_array($vars) ? $vars : [];
    }

    /**
     * Query the KB through the runtime host. Uses the workspace key.
     *
     * @return array{answer: ?string, chunks: array<int, array<string,mixed>>, raw: array<string,mixed>}
     */
    public function queryKnowledgeBase(string $question, int $chunkLimit = 5, bool $synthesis = true): array
    {
        if ($this->workspaceApiKey === null || $this->workspaceApiKey === '') {
            throw new MisconfiguredException(
                'Voiceflow KB query requires a workspace API key',
                endpoint: 'runtime.kb.query',
            );
        }

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->post('/knowledge-base/query', [
                'question' => $question,
                'chunkLimit' => max(1, min(30, $chunkLimit)),
                'synthesis' => $synthesis,
            ]);

        $response = $this->http->ensureOk($response, 'runtime.kb.query', [
            'chunk_limit' => $chunkLimit,
        ]);

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $answer = $body['output'] ?? null;
        $chunks = $body['chunks'] ?? [];

        return [
            'answer' => is_string($answer) ? $answer : null,
            'chunks' => is_array($chunks) ? $chunks : [],
            'raw' => $body,
        ];
    }

    /** Invalidate the cached sessionKey for a user. */
    public function forgetSession(string $userId): void
    {
        Cache::forget($this->sessionCacheKey($userId));
    }

    protected function sessionCacheKey(string $userId): string
    {
        return sprintf('vf_session:%s:%s:%s', $this->projectId, $this->environment, $userId);
    }

    /**
     * Probe — does NOT raise. Used by health endpoints.
     *
     * @return array{ok: bool, status: ?int, reason: ?string}
     */
    public function probe(string $userId = 'probe-user'): array
    {
        try {
            $this->sessionKey($userId);

            return ['ok' => true, 'status' => 200, 'reason' => null];
        } catch (\Throwable $e) {
            $status = method_exists($e, 'getHttpStatus') ? $e->getHttpStatus() : null;
            $status = property_exists($e, 'httpStatus') ? $e->httpStatus : $status;

            return [
                'ok' => false,
                'status' => $status,
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @internal Helper for tests/health to peek at the cached sessionKey response.
     */
    public function rawSessionResponse(string $userId): Response
    {
        $endpoint = sprintf(
            '/v4/project/%s/environment/%s/session',
            rawurlencode($this->projectId),
            rawurlencode($this->environment),
        );

        return $this->http
            ->client($this->baseUrl, $this->apiKey, timeout: 15)
            ->post($endpoint, ['userID' => $userId]);
    }
}
