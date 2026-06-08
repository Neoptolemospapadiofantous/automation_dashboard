<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\RealtimeClient;
use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowEnvironmentsTest extends TestCase
{
    private function client(): RealtimeClient
    {
        return new RealtimeClient(
            http: new VoiceflowHttpClient,
            baseUrl: 'https://realtime.voiceflow.test',
            workspaceApiKey: 'ws-key',
        );
    }

    public function test_list_environments(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environments' => Http::response([
                ['id' => 'env-1', 'alias' => 'main'],
                ['id' => 'env-2', 'alias' => 'staging'],
            ], 200),
        ]);

        $envs = $this->client()->listEnvironments('proj-1');

        $this->assertCount(2, $envs);
        $this->assertSame('main', $envs[0]['alias']);
    }

    public function test_get_environment_returns_null_on_404(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment/missing' => Http::response('gone', 404),
        ]);

        $this->assertNull($this->client()->getEnvironment('proj-1', 'missing'));
    }

    public function test_clone_environment_posts_clone_from_id(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment' => Http::response(['id' => 'env-new', 'alias' => 'staging-2'], 201),
        ]);

        $result = $this->client()->cloneEnvironment('proj-1', 'env-1', 'staging-2');

        $this->assertSame('env-new', $result['id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request['cloneFromEnvironmentID'] ?? null) === 'env-1'
            && ($request['alias'] ?? null) === 'staging-2');
    }

    public function test_publish_environment(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment/main/publish' => Http::response(['publishedVersionID' => 'v-42'], 200),
        ]);

        $result = $this->client()->publishEnvironment('proj-1', 'main');

        $this->assertSame('v-42', $result['publishedVersionID']);
    }

    public function test_export_environment_json_includes_version_query(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment/main/export-json*' => Http::response(['program' => ['blocks' => []]], 200),
        ]);

        $result = $this->client()->exportEnvironmentJson('proj-1', 'main', 'published');

        $this->assertArrayHasKey('program', $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'version=published'));
    }

    public function test_traffic_split_get_and_set(): void
    {
        Http::fake([
            'realtime.voiceflow.test/v1alpha1/project/proj-1/environment/traffic' => Http::sequence()
                ->push(['main' => 100], 200)
                ->push(['main' => 80, 'canary' => 20], 200),
        ]);

        $client = $this->client();

        $this->assertSame(100, $client->getTrafficSplit('proj-1')['main']);

        $updated = $client->setTrafficSplit('proj-1', ['main' => 80, 'canary' => 20]);
        $this->assertSame(80, $updated['main']);
        $this->assertSame(20, $updated['canary']);
    }
}
