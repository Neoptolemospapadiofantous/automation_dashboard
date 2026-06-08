<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\AnalyticsClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use App\Services\Voiceflow\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowEvaluationsTest extends TestCase
{
    private function client(): AnalyticsClient
    {
        return new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: 'ws-key',
            projectId: 'proj-1',
        );
    }

    public function test_create_posts_definition(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation' => Http::response(['id' => 'eval-1', 'name' => 'Politeness'], 201),
        ]);

        $result = $this->client()->createEvaluation([
            'projectID' => 'proj-1',
            'name' => 'Politeness',
            'type' => 'boolean',
        ]);

        $this->assertSame('eval-1', $result['id']);
    }

    public function test_list_returns_array_for_project(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/project/proj-1' => Http::response([
                ['id' => 'eval-1'],
                ['id' => 'eval-2'],
            ], 200),
        ]);

        $this->assertCount(2, $this->client()->listEvaluations());
    }

    public function test_get_returns_null_on_404(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/*' => Http::response('gone', 404),
        ]);

        $this->assertNull($this->client()->getEvaluation('gone-id'));
    }

    public function test_run_evaluates_against_one_transcript(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/eval-1/transcript/t-99' => Http::response(['score' => 0.9, 'passed' => true], 200),
        ]);

        $result = $this->client()->runEvaluation('eval-1', 't-99');

        $this->assertSame(0.9, $result['score']);
        $this->assertTrue($result['passed']);
    }

    public function test_queue_batch_returns_partial_success_warning(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/queue' => Http::response([
                'queued' => 90,
                'warning' => ['skippedTranscriptIDs' => ['t-91', 't-92']],
            ], 200),
        ]);

        $result = $this->client()->queueBatchEvaluation([
            'evaluationIDs' => ['eval-1'],
            'transcriptIDs' => array_map(fn ($i) => "t-{$i}", range(1, 100)),
        ]);

        $this->assertSame(90, $result['queued']);
        $this->assertCount(2, $result['warning']['skippedTranscriptIDs']);
    }

    public function test_429_translates_to_rate_limited_exception_with_retry_after(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript-evaluation/queue' => Http::response('quota', 429, ['Retry-After' => '30']),
        ]);

        try {
            $this->client()->queueBatchEvaluation(['evaluationIDs' => ['eval-1']]);
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
            $this->assertSame(429, $e->httpStatus);
        }
    }
}
