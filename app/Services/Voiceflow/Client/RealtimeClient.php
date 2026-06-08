<?php

namespace App\Services\Voiceflow\Client;

use App\Services\Voiceflow\Exceptions\KbUploadException;
use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use Generator;

/**
 * Wraps the Voiceflow `realtime-api` host:
 *   - /v1alpha1/public/knowledge-base/document/* (CRUD)
 *   - /v1alpha1/project/{p}/environment/* (future Phase E)
 *
 * Uses the workspace API key.
 */
class RealtimeClient
{
    /** Max page size accepted by KB list. */
    public const KB_PAGE_MAX = 100;

    /** Hard cap on KB file upload size per the upstream contract. */
    public const KB_FILE_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        protected VoiceflowHttpClient $http,
        protected string $baseUrl,
        protected string $workspaceApiKey,
    ) {
        if ($workspaceApiKey === '') {
            throw new MisconfiguredException(
                'Voiceflow Realtime requires a workspace API key',
                endpoint: 'realtime',
            );
        }
    }

    /**
     * List KB documents — one page.
     *
     * @return array{total: int, data: array<int, array<string,mixed>>}
     */
    public function listKbDocuments(int $page = 1, int $limit = 20, ?string $documentType = null): array
    {
        $page = max(1, $page);
        $limit = max(1, min(self::KB_PAGE_MAX, $limit));

        $params = ['page' => $page, 'limit' => $limit];
        if ($documentType !== null) {
            $params['documentType'] = $documentType;
        }

        $endpoint = '/v1alpha1/public/knowledge-base/document?'.http_build_query($params);

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.kb.list', [
            'page' => $page,
            'limit' => $limit,
            'type' => $documentType,
        ]);

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        return [
            'total' => (int) ($body['total'] ?? 0),
            'data' => is_array($body['data'] ?? null) ? $body['data'] : [],
        ];
    }

    /**
     * Stream every KB document across pages — yields raw rows.
     * Fixes the silent truncation that bit us when tenants had > 50 documents.
     *
     * @return Generator<int, array<string,mixed>>
     */
    public function kbDocumentStream(?string $documentType = null): Generator
    {
        $page = 1;
        $limit = self::KB_PAGE_MAX;

        while (true) {
            $result = $this->listKbDocuments($page, $limit, $documentType);

            foreach ($result['data'] as $row) {
                yield $row;
            }

            if (count($result['data']) < $limit) {
                return;
            }

            $page++;
        }
    }

    /**
     * Create a URL-source KB document.
     *
     * @return array<string,mixed> raw `data` field from the response
     */
    public function createUrlDocument(string $url, ?string $name = null): array
    {
        $body = [
            'data' => [
                'type' => 'url',
                'url' => $url,
            ],
        ];
        if ($name !== null && $name !== '') {
            $body['data']['name'] = $name;
        }

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->post('/v1alpha1/public/knowledge-base/document', $body);

        $response = $this->http->ensureOk($response, 'realtime.kb.create.url', [
            'url' => $url,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Create a file-source KB document via streaming upload.
     * Fixes the legacy `file_get_contents` full-in-memory read.
     *
     * @return array<string,mixed> raw `data` field from the response
     */
    public function createFileDocument(string $filePath, string $name): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new KbUploadException("KB upload source not readable: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size === false || $size === 0) {
            throw new KbUploadException("KB upload source is empty: {$filePath}");
        }
        if ($size > self::KB_FILE_MAX_BYTES) {
            throw new KbUploadException(sprintf(
                'KB upload exceeds %d MB cap (%d bytes)',
                (int) (self::KB_FILE_MAX_BYTES / 1024 / 1024),
                $size,
            ));
        }

        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            throw new KbUploadException("KB upload could not open stream: {$filePath}");
        }

        try {
            $response = $this->http
                ->client($this->baseUrl, $this->workspaceApiKey, timeout: 60)
                ->attach('file', $stream, basename($name))
                ->post('/v1alpha1/public/knowledge-base/document');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $response = $this->http->ensureOk($response, 'realtime.kb.create.file', [
            'name' => basename($name),
            'size' => $size,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Fetch a single document with chunks + metadata.
     *
     * @return array<string,mixed>
     */
    public function getDocument(string $documentId): array
    {
        $endpoint = sprintf('/v1alpha1/public/knowledge-base/document/%s', rawurlencode($documentId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.kb.get', [
            'document_id' => $documentId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Delete a KB document.
     */
    public function deleteDocument(string $documentId): void
    {
        $endpoint = sprintf('/v1alpha1/public/knowledge-base/document/%s', rawurlencode($documentId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->delete($endpoint);

        $this->http->ensureOk($response, 'realtime.kb.delete', [
            'document_id' => $documentId,
        ]);
    }

    /**
     * Create a text-paste KB document (raw string body, no file upload).
     *
     * @return array<string,mixed> raw `data` field from the response
     */
    public function createTextDocument(string $text, string $name): array
    {
        $body = [
            'data' => [
                'type' => 'text',
                'name' => $name,
                'text' => $text,
            ],
        ];

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->post('/v1alpha1/public/knowledge-base/document', $body);

        $response = $this->http->ensureOk($response, 'realtime.kb.create.text', [
            'name' => $name,
            'length' => mb_strlen($text),
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Replace a KB document's content (PUT).
     *
     * @param  array<string,mixed>  $data  Payload body's `data` field.
     * @return array<string,mixed> raw response data
     */
    public function replaceDocument(string $documentId, array $data): array
    {
        $endpoint = sprintf('/v1alpha1/public/knowledge-base/document/%s', rawurlencode($documentId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->put($endpoint, ['data' => $data]);

        $response = $this->http->ensureOk($response, 'realtime.kb.replace', [
            'document_id' => $documentId,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Patch document-level metadata (tags, name, custom fields).
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed> raw response data
     */
    public function patchDocument(string $documentId, array $metadata): array
    {
        $endpoint = sprintf('/v1alpha1/public/knowledge-base/document/%s', rawurlencode($documentId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->patch($endpoint, ['data' => $metadata]);

        $response = $this->http->ensureOk($response, 'realtime.kb.patch', [
            'document_id' => $documentId,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Patch a single chunk's metadata (for fine-grained tagging).
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed> raw response data
     */
    public function patchChunk(string $documentId, string $chunkId, array $metadata): array
    {
        $endpoint = sprintf(
            '/v1alpha1/public/knowledge-base/document/%s/chunk/%s',
            rawurlencode($documentId),
            rawurlencode($chunkId),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->patch($endpoint, ['data' => $metadata]);

        $response = $this->http->ensureOk($response, 'realtime.kb.chunk.patch', [
            'document_id' => $documentId,
            'chunk_id' => $chunkId,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    // ── Projects / Environments ───────────────────────────────────────────────

    /**
     * List all environments for a project.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listEnvironments(string $projectId): array
    {
        $endpoint = sprintf('/v1alpha1/project/%s/environments', rawurlencode($projectId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.environments.list', [
            'project_id' => $projectId,
        ]);

        $body = $response->json();

        return is_array($body) ? array_values($body) : [];
    }

    /**
     * Fetch one environment by ID or alias.
     *
     * @return array<string,mixed>|null
     */
    public function getEnvironment(string $projectId, string $idOrAlias): ?array
    {
        $endpoint = sprintf(
            '/v1alpha1/project/%s/environment/%s',
            rawurlencode($projectId),
            rawurlencode($idOrAlias),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        if ($response->status() === 404) {
            return null;
        }

        $response = $this->http->ensureOk($response, 'realtime.environments.get', [
            'project_id' => $projectId,
            'id_or_alias' => $idOrAlias,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * Clone an existing environment. Voiceflow has NO create-from-scratch.
     *
     * @return array<string,mixed>
     */
    public function cloneEnvironment(string $projectId, string $sourceEnvironmentId, string $alias): array
    {
        $endpoint = sprintf('/v1alpha1/project/%s/environment', rawurlencode($projectId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 30)
            ->post($endpoint, [
                'cloneFromEnvironmentID' => $sourceEnvironmentId,
                'alias' => $alias,
            ]);

        $response = $this->http->ensureOk($response, 'realtime.environments.clone', [
            'project_id' => $projectId,
            'source' => $sourceEnvironmentId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Delete an environment (by ID only, not alias).
     */
    public function deleteEnvironment(string $projectId, string $environmentId): void
    {
        $endpoint = sprintf(
            '/v1alpha1/project/%s/environment/%s',
            rawurlencode($projectId),
            rawurlencode($environmentId),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 15)
            ->delete($endpoint);

        $this->http->ensureOk($response, 'realtime.environments.delete', [
            'project_id' => $projectId,
            'environment_id' => $environmentId,
        ]);
    }

    /**
     * Publish a draft environment as a release.
     *
     * @return array<string,mixed>
     */
    public function publishEnvironment(string $projectId, string $idOrAlias): array
    {
        $endpoint = sprintf(
            '/v1alpha1/project/%s/environment/%s/publish',
            rawurlencode($projectId),
            rawurlencode($idOrAlias),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 60)
            ->post($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.environments.publish', [
            'project_id' => $projectId,
            'id_or_alias' => $idOrAlias,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Export the assistant JSON for an environment (for backup / version control).
     *
     * @return array<string,mixed>
     */
    public function exportEnvironmentJson(string $projectId, string $alias, string $version = 'published'): array
    {
        $endpoint = sprintf(
            '/v1alpha1/project/%s/environment/%s/export-json?version=%s',
            rawurlencode($projectId),
            rawurlencode($alias),
            rawurlencode($version),
        );

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 60)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.environments.export', [
            'project_id' => $projectId,
            'alias' => $alias,
            'version' => $version,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Read the current traffic split between environments.
     *
     * @return array<string,mixed>
     */
    public function getTrafficSplit(string $projectId): array
    {
        $endpoint = sprintf('/v1alpha1/project/%s/environment/traffic', rawurlencode($projectId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->get($endpoint);

        $response = $this->http->ensureOk($response, 'realtime.environments.traffic.get', [
            'project_id' => $projectId,
        ]);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Update the traffic split. The sum of percentages must equal 100.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function setTrafficSplit(string $projectId, array $body): array
    {
        $endpoint = sprintf('/v1alpha1/project/%s/environment/traffic', rawurlencode($projectId));

        $response = $this->http
            ->client($this->baseUrl, $this->workspaceApiKey, timeout: 20)
            ->patch($endpoint, $body);

        $response = $this->http->ensureOk($response, 'realtime.environments.traffic.set', [
            'project_id' => $projectId,
        ]);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Upload tabular KB content (CSV/XLSX-style structured rows).
     *
     * @return array<string,mixed> raw response data
     */
    public function uploadTableDocument(string $filePath, string $name): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new KbUploadException("KB table upload source not readable: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size === false || $size === 0) {
            throw new KbUploadException("KB table upload source is empty: {$filePath}");
        }
        if ($size > self::KB_FILE_MAX_BYTES) {
            throw new KbUploadException(sprintf(
                'KB table upload exceeds %d MB cap (%d bytes)',
                (int) (self::KB_FILE_MAX_BYTES / 1024 / 1024),
                $size,
            ));
        }

        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            throw new KbUploadException("KB table upload could not open stream: {$filePath}");
        }

        try {
            $response = $this->http
                ->client($this->baseUrl, $this->workspaceApiKey, timeout: 60)
                ->attach('file', $stream, basename($name))
                ->post('/v1alpha1/public/knowledge-base/document/upload/table');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $response = $this->http->ensureOk($response, 'realtime.kb.upload.table', [
            'name' => basename($name),
            'size' => $size,
        ]);

        $payload = $response->json();

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }
}
