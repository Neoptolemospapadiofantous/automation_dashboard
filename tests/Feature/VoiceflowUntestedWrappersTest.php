<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\AnalyticsClient;
use App\Services\Voiceflow\Client\RealtimeClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Direct coverage for wrapper methods the fleet flagged as UNTESTED_CLIENT_METHOD
 * on 2026-06-08. See docs/hermes/FLEET-2026-06-08_16-55.md.
 */
class VoiceflowUntestedWrappersTest extends TestCase
{
    private function analytics(): AnalyticsClient
    {
        return new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: 'ws-key',
            projectId: 'proj-1',
        );
    }

    private function realtime(): RealtimeClient
    {
        return new RealtimeClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://realtime.voiceflow.test',
            workspaceApiKey: 'ws-key',
        );
    }

    // ── AnalyticsClient ───────────────────────────────────────────────────────

    public function test_query_usage_posts_metric_body(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v2/query/usage' => Http::response(['data' => ['count' => 1234]], 200),
        ]);

        $result = $this->analytics()->queryUsage([
            'data' => ['name' => 'interactions'],
            'limit' => 1,
        ]);

        $this->assertSame(1234, $result['data']['count']);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/v2/query/usage')
            && ($r['data']['name'] ?? null) === 'interactions'
            && $r->header('authorization')[0] === 'ws-key');
    }

    public function test_update_evaluation_patches_definition(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/eval-1' => Http::response(['id' => 'eval-1', 'name' => 'New name'], 200),
        ]);

        $result = $this->analytics()->updateEvaluation('eval-1', ['name' => 'New name']);

        $this->assertSame('New name', $result['name']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/v1/transcript-evaluation/eval-1')
            && ($r['name'] ?? null) === 'New name');
    }

    public function test_delete_evaluation_calls_delete_endpoint(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/eval-1' => Http::response('', 204),
        ]);

        $this->analytics()->deleteEvaluation('eval-1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/v1/transcript-evaluation/eval-1'));
    }

    public function test_estimate_evaluation_posts_to_estimate_endpoint(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/estimate' => Http::response([
                'estimatedCost' => 0.42,
                'evaluations' => 1,
                'transcripts' => 10,
            ], 200),
        ]);

        $result = $this->analytics()->estimateEvaluation([
            'evaluationIDs' => ['eval-1'],
            'transcriptIDs' => array_map(fn ($i) => "t-{$i}", range(1, 10)),
        ]);

        $this->assertSame(0.42, $result['estimatedCost']);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/v1/transcript-evaluation/estimate'));
    }

    // ── RealtimeClient ────────────────────────────────────────────────────────

    public function test_replace_document_puts_payload(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/public/knowledge-base/document/doc-1' => Http::response(['data' => ['id' => 'doc-1', 'name' => 'updated']], 200),
        ]);

        $result = $this->realtime()->replaceDocument('doc-1', ['type' => 'text', 'text' => 'new content', 'name' => 'updated']);

        $this->assertSame('updated', $result['name']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/document/doc-1')
            && ($r['data']['type'] ?? null) === 'text');
    }

    public function test_patch_document_updates_metadata(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/public/knowledge-base/document/doc-1' => Http::response(['data' => ['id' => 'doc-1', 'tags' => ['policy']]], 200),
        ]);

        $result = $this->realtime()->patchDocument('doc-1', ['tags' => ['policy']]);

        $this->assertSame(['policy'], $result['tags']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/document/doc-1')
            && ($r['data']['tags'] ?? null) === ['policy']);
    }

    public function test_patch_chunk_targets_chunk_subresource(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/public/knowledge-base/document/doc-1/chunk/chunk-9' => Http::response(['data' => ['id' => 'chunk-9', 'tags' => ['fy26']]], 200),
        ]);

        $result = $this->realtime()->patchChunk('doc-1', 'chunk-9', ['tags' => ['fy26']]);

        $this->assertSame(['fy26'], $result['tags']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/document/doc-1/chunk/chunk-9'));
    }

    public function test_upload_table_document_streams_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kb-table-');
        file_put_contents($tmp, "col1,col2\nrow1a,row1b\n");

        try {
            Http::fake([
                'realtime.voiceflow.test/v1alpha1/public/knowledge-base/document/upload/table' => Http::response(['data' => ['id' => 'tbl-1']], 201),
            ]);

            $result = $this->realtime()->uploadTableDocument($tmp, 'companies.csv');

            $this->assertSame('tbl-1', $result['id']);
            Http::assertSent(fn ($r) => $r->method() === 'POST'
                && str_contains($r->url(), '/document/upload/table'));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_delete_environment(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment/env-doomed' => Http::response('', 204),
        ]);

        $this->realtime()->deleteEnvironment('proj-1', 'env-doomed');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/environment/env-doomed'));
    }
}
