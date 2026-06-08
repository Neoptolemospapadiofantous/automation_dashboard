<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\AnalyticsClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowAnalyticsClientTest extends TestCase
{
    public function test_misconfigured_when_workspace_key_empty(): void
    {
        $this->expectException(MisconfiguredException::class);

        new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: '',
            projectId: 'proj-1',
        );
    }

    public function test_transcript_stream_paginates_across_full_pages(): void
    {
        // 3 full pages then a 1-row partial page = 301 total rows.
        $page1 = array_map(fn ($i) => ['id' => "t{$i}"], range(1, 100));
        $page2 = array_map(fn ($i) => ['id' => "t{$i}"], range(101, 200));
        $page3 = array_map(fn ($i) => ['id' => "t{$i}"], range(201, 300));
        $page4 = [['id' => 't301']];

        Http::fakeSequence('analytics.voiceflow.test/v1/transcript/project/*')
            ->push(['transcripts' => $page1], 200)
            ->push(['transcripts' => $page2], 200)
            ->push(['transcripts' => $page3], 200)
            ->push(['transcripts' => $page4], 200);

        $client = new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: 'ws-key',
            projectId: 'proj-1',
        );

        $rows = iterator_to_array($client->transcriptStream(), false);

        $this->assertCount(301, $rows);
        $this->assertSame('t1', $rows[0]['id']);
        $this->assertSame('t301', $rows[300]['id']);

        // 4 pages fetched.
        Http::assertSentCount(4);
    }

    public function test_get_transcript_returns_null_on_404(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript/*' => Http::response('missing', 404),
        ]);

        $client = new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: 'ws-key',
            projectId: 'proj-1',
        );

        $this->assertNull($client->getTranscript('gone-id'));
    }

    public function test_get_transcript_returns_payload(): void
    {
        Http::fake([
            'analytics.voiceflow.test/v1/transcript/t-99*' => Http::response(['transcript' => ['id' => 't-99', 'logs' => []]], 200),
        ]);

        $client = new AnalyticsClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://analytics.voiceflow.test',
            workspaceApiKey: 'ws-key',
            projectId: 'proj-1',
        );

        $result = $client->getTranscript('t-99');

        $this->assertSame('t-99', $result['id']);
    }
}
