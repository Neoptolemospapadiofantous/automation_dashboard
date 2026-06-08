<?php

namespace App\Services\Voiceflow\Client;

use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use Generator;

/**
 * Wraps the Voiceflow `analytics-api` host:
 *   - /v1/transcript/* (search, fetch, end, delete, properties, values)
 *   - /v1/transcript-evaluation/* (create, list, get, update, delete, run, queue, estimate)
 *   - /v2/query/usage
 *
 * Uses the workspace API key.
 */
class AnalyticsClient
{
    /** Max page size accepted by /v1/transcript/project/{p}. */
    public const TRANSCRIPT_PAGE_MAX = 100;

    public function __construct(
        protected VoiceflowHttpClient $http,
        protected string $baseUrl,
        protected string $workspaceApiKey,
        protected string $projectId,
    ) {
        if ($workspaceApiKey === '') {
            throw new MisconfiguredException(
                'Voiceflow Analytics requires a workspace API key',
                endpoint: 'analytics',
            );
        }
    }

    /**
     * Fetch a single page of transcripts. Use `transcriptStream()` for full iteration.
     *
     * @param  array<string,mixed>  $body
     * @return array<int, array<string,mixed>>
     */
    public function searchTranscripts(int $take = 25, int $skip = 0, array $body = []): array
    {
        $take = max(1, min(self::TRANSCRIPT_PAGE_MAX, $take));
        $skip = max(0, $skip);

        $endpoint = sprintf(
            '/v1/transcript/project/%s?take=%d&skip=%d&order=DESC',
            rawurlencode($this->projectId),
            $take,
            $skip,
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->post($endpoint, $body);

        $response = $this->http->ensureOk($response, 'analytics.transcripts.search', [
            'project_id' => $this->projectId,
            'take' => $take,
            'skip' => $skip,
        ]);

        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        // Voiceflow wraps the rows in {transcripts: [...]} in V1; some legacy
        // / mocked responses return a bare array — accept both shapes.
        $rows = $payload['transcripts'] ?? $payload;

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Stream every transcript across multiple pages — yields raw rows.
     * Fixes the silent truncation that bit us when tenants had > 100 transcripts.
     *
     * @param  array<string,mixed>  $body
     * @return Generator<int, array<string,mixed>>
     */
    public function transcriptStream(array $body = []): Generator
    {
        $skip = 0;
        $take = self::TRANSCRIPT_PAGE_MAX;

        while (true) {
            $page = $this->searchTranscripts($take, $skip, $body);

            foreach ($page as $row) {
                yield $row;
            }

            if (count($page) < $take) {
                return;
            }

            $skip += $take;
        }
    }

    /**
     * Fetch one transcript with logs.
     *
     * @return array<string,mixed>|null null on 404
     */
    public function getTranscript(string $transcriptId): ?array
    {
        $endpoint = sprintf('/v1/transcript/%s?filterConversation=true', rawurlencode($transcriptId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        if ($response->status() === 404) {
            return null;
        }

        $response = $this->http->ensureOk($response, 'analytics.transcripts.get', [
            'transcript_id' => $transcriptId,
        ]);

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        // Same dual-shape handling as searchTranscripts.
        $transcript = $body['transcript'] ?? $body;

        return is_array($transcript) ? $transcript : null;
    }

    /**
     * Mark a transcript ended.
     */
    public function endTranscript(string $transcriptId): void
    {
        $endpoint = sprintf(
            '/v1/transcript/%s/project/%s/end',
            rawurlencode($transcriptId),
            rawurlencode($this->projectId),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->post($endpoint);

        $this->http->ensureOk($response, 'analytics.transcripts.end', [
            'transcript_id' => $transcriptId,
        ]);
    }

    /**
     * Delete a transcript (GDPR / sensitive-data scrubbing).
     */
    public function deleteTranscript(string $transcriptId): void
    {
        $endpoint = sprintf('/v1/transcript/%s', rawurlencode($transcriptId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->delete($endpoint);

        $this->http->ensureOk($response, 'analytics.transcripts.delete', [
            'transcript_id' => $transcriptId,
        ]);
    }

    // ── Evaluations (transcript-evaluation) ───────────────────────────────────

    /**
     * Create an evaluation definition.
     *
     * @param  array<string,mixed>  $body  Per docs/voiceflow/evaluations/create-transcript-evaluation.md
     * @return array<string,mixed>
     */
    public function createEvaluation(array $body): array
    {
        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->post('/v1/transcript-evaluation', $body);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.create', [
            'project_id' => $this->projectId,
        ]);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * List evaluations for the current project.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listEvaluations(): array
    {
        $endpoint = sprintf('/v1/transcript-evaluation/project/%s', rawurlencode($this->projectId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.list', [
            'project_id' => $this->projectId,
        ]);

        $body = $response->json();

        return is_array($body) ? array_values($body) : [];
    }

    /**
     * Fetch one evaluation definition.
     *
     * @return array<string,mixed>|null
     */
    public function getEvaluation(string $evaluationId): ?array
    {
        $endpoint = sprintf('/v1/transcript-evaluation/%s', rawurlencode($evaluationId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        if ($response->status() === 404) {
            return null;
        }

        $response = $this->http->ensureOk($response, 'analytics.evaluations.get', [
            'evaluation_id' => $evaluationId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * Update an evaluation definition.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function updateEvaluation(string $evaluationId, array $body): array
    {
        $endpoint = sprintf('/v1/transcript-evaluation/%s', rawurlencode($evaluationId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->patch($endpoint, $body);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.update', [
            'evaluation_id' => $evaluationId,
        ]);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Delete an evaluation definition (and all its results).
     */
    public function deleteEvaluation(string $evaluationId): void
    {
        $endpoint = sprintf('/v1/transcript-evaluation/%s', rawurlencode($evaluationId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->delete($endpoint);

        $this->http->ensureOk($response, 'analytics.evaluations.delete', [
            'evaluation_id' => $evaluationId,
        ]);
    }

    /**
     * Run an evaluation against a single transcript, synchronously.
     *
     * @return array<string,mixed>
     */
    public function runEvaluation(string $evaluationId, string $transcriptId): array
    {
        $endpoint = sprintf(
            '/v1/transcript-evaluation/%s/transcript/%s',
            rawurlencode($evaluationId),
            rawurlencode($transcriptId),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 60)
            ->post($endpoint);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.run', [
            'evaluation_id' => $evaluationId,
            'transcript_id' => $transcriptId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Queue a batch evaluation: up to 10 evals × 100 transcripts. Voiceflow
     * can partially succeed — handle `warning.skippedTranscriptIDs` upstream.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function queueBatchEvaluation(array $body): array
    {
        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->post('/v1/transcript-evaluation/queue', $body);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.queue', []);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Pre-flight estimate for a batch evaluation cost.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function estimateEvaluation(array $body): array
    {
        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->post('/v1/transcript-evaluation/estimate', $body);

        $response = $this->http->ensureOk($response, 'analytics.evaluations.estimate', []);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * POST /v2/query/usage — aggregate analytics.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function queryUsage(array $body): array
    {
        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->post('/v2/query/usage', $body);

        $response = $this->http->ensureOk($response, 'analytics.usage.query', [
            'metric' => $body['data']['name'] ?? null,
        ]);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }
}
