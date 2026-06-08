<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\RuntimeClient;
use App\Services\Voiceflow\Client\StreamingClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowStreamingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeClient(): StreamingClient
    {
        $http = new VoiceflowHttpClient;
        $runtime = new RuntimeClient(
            http: $http,
            baseUrl: 'https://runtime.voiceflow.test',
            apiKey: 'VF.DM.test',
            projectId: 'proj-1',
            environment: 'main',
            workspaceApiKey: null,
        );

        return new StreamingClient(
            runtime: $runtime,
            baseUrl: 'https://runtime.voiceflow.test',
            projectId: 'proj-1',
            environment: 'main',
        );
    }

    public function test_parses_well_formed_sse_stream(): void
    {
        Http::fake([
            'runtime.voiceflow.test/v4/project/*/session' => Http::response(['sessionKey' => 'sk-abc'], 200),
        ]);

        $rawStream = [
            "event: trace\ndata: ",
            '{"type":"text","payload":{"message":"Hello"}}',
            "\n\n",
            "event: trace\ndata: {\"type\":\"end\",\"payload\":{}}\n\n",
        ];

        $events = iterator_to_array(
            $this->makeClient()->streamInteract(
                userId: 'u-1',
                action: ['type' => 'launch'],
                reader: fn () => $rawStream,
            ),
            false,
        );

        $this->assertCount(2, $events);
        $this->assertSame('trace', $events[0]['event']);
        $this->assertSame('text', $events[0]['data']['type']);
        $this->assertSame('Hello', $events[0]['data']['payload']['message']);
        $this->assertSame('end', $events[1]['data']['type']);
    }

    public function test_handles_multi_line_data_field(): void
    {
        Http::fake([
            'runtime.voiceflow.test/v4/project/*/session' => Http::response(['sessionKey' => 'sk-multi'], 200),
        ]);

        $rawStream = [
            "event: trace\ndata: {\n",
            "data: \"type\": \"text\",\n",
            "data: \"payload\": {\"message\": \"two-line\"}\n",
            "data: }\n\n",
        ];

        $events = iterator_to_array(
            $this->makeClient()->streamInteract('u-1', ['type' => 'launch'], reader: fn () => $rawStream),
            false,
        );

        $this->assertCount(1, $events);
        $this->assertSame('two-line', $events[0]['data']['payload']['message']);
    }

    public function test_skips_unparseable_event_without_crashing(): void
    {
        Http::fake([
            'runtime.voiceflow.test/v4/project/*/session' => Http::response(['sessionKey' => 'sk-bad'], 200),
        ]);

        $rawStream = [
            "event: trace\ndata: not-json\n\n",
            "event: trace\ndata: {\"type\":\"ok\"}\n\n",
        ];

        $events = iterator_to_array(
            $this->makeClient()->streamInteract('u-1', ['type' => 'launch'], reader: fn () => $rawStream),
            false,
        );

        // The bad event is dropped; the good one passes through.
        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]['data']['type']);
    }
}
