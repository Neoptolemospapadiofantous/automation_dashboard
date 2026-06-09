<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\RealtimeClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use App\Services\Voiceflow\Exceptions\KbUploadException;
use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowRealtimeClientTest extends TestCase
{
    public function test_misconfigured_when_workspace_key_empty(): void
    {
        $client = new RealtimeClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://realtime.voiceflow.test',
            workspaceApiKey: '',
        );

        $this->expectException(MisconfiguredException::class);
        $client->listKbDocuments();
    }

    public function test_kb_document_stream_paginates(): void
    {
        $page1 = array_map(fn ($i) => ['id' => "d{$i}"], range(1, 100));
        $page2 = array_map(fn ($i) => ['id' => "d{$i}"], range(101, 150));

        Http::fakeSequence('realtime.voiceflow.test/v1alpha1/public/knowledge-base/document*')
            ->push(['total' => 150, 'data' => $page1], 200)
            ->push(['total' => 150, 'data' => $page2], 200);

        $client = new RealtimeClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://realtime.voiceflow.test',
            workspaceApiKey: 'ws-key',
        );

        $rows = iterator_to_array($client->kbDocumentStream(), false);

        $this->assertCount(150, $rows);
        Http::assertSentCount(2);
    }

    public function test_create_file_document_rejects_oversized_upload(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'kb-oversize-');
        file_put_contents($tmpPath, str_repeat('A', 11 * 1024 * 1024));

        try {
            $client = new RealtimeClient(
                http: new VoiceflowHttpClient,
                baseUrl: 'https://realtime.voiceflow.test',
                workspaceApiKey: 'ws-key',
            );

            $this->expectException(KbUploadException::class);
            $this->expectExceptionMessageMatches('/exceeds.*MB cap/i');

            $client->createFileDocument($tmpPath, 'huge.pdf');
        } finally {
            @unlink($tmpPath);
        }
    }

    public function test_create_file_document_rejects_empty_upload(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'kb-empty-');
        file_put_contents($tmpPath, '');

        try {
            $client = new RealtimeClient(
                http: new VoiceflowHttpClient,
                baseUrl: 'https://realtime.voiceflow.test',
                workspaceApiKey: 'ws-key',
            );

            $this->expectException(KbUploadException::class);
            $this->expectExceptionMessageMatches('/empty/i');

            $client->createFileDocument($tmpPath, 'zero.pdf');
        } finally {
            @unlink($tmpPath);
        }
    }
}
