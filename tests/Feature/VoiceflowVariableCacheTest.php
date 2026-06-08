<?php

namespace Tests\Feature;

use App\Services\VoiceflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowVariableCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.api_key', 'VF.DM.test');
        config()->set('services.voiceflow.project_id', 'proj-123');
        config()->set('services.voiceflow.environment', 'main');
        config()->set('services.voiceflow.runtime_url', 'https://general-runtime.voiceflow.test');

        Cache::flush();
    }

    public function test_get_variables_caches_for_30_seconds(): void
    {
        Http::fake([
            'general-runtime.voiceflow.test/state/user/u-1' => Http::response(['variables' => ['name' => 'Ada']], 200),
        ]);

        $svc = new VoiceflowService;

        $first = $svc->getVariables('u-1');
        $second = $svc->getVariables('u-1');

        $this->assertSame(['name' => 'Ada'], $first);
        $this->assertSame($first, $second);

        // Exactly ONE upstream call — the second was served from cache.
        Http::assertSentCount(1);
    }

    public function test_get_variables_uses_project_scoped_cache_key(): void
    {
        Http::fake([
            'general-runtime.voiceflow.test/state/user/u-1' => Http::response(['variables' => ['name' => 'Ada']], 200),
        ]);

        (new VoiceflowService)->getVariables('u-1');

        $this->assertTrue(Cache::has('vf_vars:proj-123:u-1'));
        $this->assertFalse(Cache::has('vf_vars:proj-other:u-1'));
    }
}
