<?php

namespace Tests\Feature;

use App\Models\AgentFinding;
use App\Support\Findings\FindingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FindingsStoreTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function providerRun(string $ts, string $overall = 'PASS'): array
    {
        return ['ts' => $ts, 'overall' => $overall, 'pass' => 3, 'warn' => 0, 'fail' => 0,
            'checks' => [['check' => 'provider-openai', 'status' => 'PASS', 'detail' => 'ok']]];
    }

    public function test_record_is_idempotent_on_collector_and_ts_and_latest_returns_the_newest(): void
    {
        $store = app(FindingsStore::class);

        $this->assertTrue($store->record('provider-health', $this->providerRun('2026-08-30T18:00:08Z')));
        $this->assertFalse($store->record('provider-health', $this->providerRun('2026-08-30T18:00:08Z')), 're-ingesting the same run must be a no-op');
        $this->assertTrue($store->record('provider-health', $this->providerRun('2026-08-30T19:00:08Z', 'WARN')));
        $this->assertSame(2, AgentFinding::count());

        $latest = $store->latest();
        $this->assertSame('2026-08-30T19:00:08Z', $latest['provider-health']['ts']);
        $this->assertSame('WARN', $latest['provider-health']['overall']);
        // The payload is the collector's own document, untouched.
        $this->assertSame('provider-openai', $latest['provider-health']['payload']['checks'][0]['check']);
        $this->assertArrayNotHasKey('system-check', $latest);
    }

    public function test_prune_keeps_recent_history_and_a_floor_per_collector(): void
    {
        $store = app(FindingsStore::class);
        for ($i = 0; $i < 60; $i++) {
            $store->record('system-check', ['ts' => now()->subDays(90)->addHours($i)->toIso8601ZuluString(), 'overall' => 'PASS']);
        }
        $store->record('system-check', ['ts' => now()->toIso8601ZuluString(), 'overall' => 'PASS']);

        $deleted = $store->prune(days: 30, keep: 50);

        $this->assertSame(11, $deleted, 'older than 30d AND beyond the newest 50 go');
        $this->assertSame(50, AgentFinding::where('collector', 'system-check')->count());
    }

    public function test_ingest_command_reads_the_tree_and_skips_what_it_already_holds(): void
    {
        $base = sys_get_temp_dir().'/findings-'.uniqid();
        File::ensureDirectoryExists($base.'/audit-sentinel');
        File::ensureDirectoryExists($base.'/update-inspector');
        File::put($base.'/audit-sentinel/findings.json', json_encode([
            'ts' => '2026-08-30T06:00:00Z', 'overall' => 'WARN', 'critical' => 0, 'high' => 0, 'medium' => 1, 'low' => 0,
            'findings' => [['severity' => 'MEDIUM', 'check' => 'env-missing-keys', 'detail' => '2 keys']],
        ]));
        File::put($base.'/update-inspector/findings.json', 'not json');

        $this->artisan('findings:ingest', ['--path' => $base])
            ->expectsOutputToContain('audit-sentinel: recorded 2026-08-30T06:00:00Z (WARN)')
            ->expectsOutputToContain('update-inspector: findings.json is not valid JSON')
            ->expectsOutputToContain('Ingested 1 run(s)')
            ->assertSuccessful();

        $this->artisan('findings:ingest', ['--path' => $base])
            ->expectsOutputToContain('Ingested 0 run(s)')
            ->assertSuccessful();

        $this->assertSame(1, AgentFinding::count());
        File::deleteDirectory($base);
    }

    public function test_endpoint_is_503_unconfigured_401_wrong_bearer_and_serves_latest_per_collector(): void
    {
        app(FindingsStore::class)->record('provider-health', $this->providerRun('2026-08-30T18:00:08Z'));

        config(['services.findings.token' => null]);
        $this->getJson('/api/findings')->assertStatus(503);

        config(['services.findings.token' => 'grid-secret']);
        $this->getJson('/api/findings')->assertStatus(401);
        $this->withToken('nope')->getJson('/api/findings')->assertStatus(401);

        $this->withToken('grid-secret')->getJson('/api/findings')
            ->assertOk()
            ->assertJsonPath('collectors.provider-health.overall', 'PASS')
            ->assertJsonPath('collectors.provider-health.payload.checks.0.check', 'provider-openai')
            ->assertJsonMissingPath('collectors.system-check');

        // An empty tree is an object, not a list — the mirror indexes by collector name.
        AgentFinding::query()->delete();
        $this->assertStringContainsString('"collectors":{}', (string) $this->withToken('grid-secret')->getJson('/api/findings')->getContent());
    }
}
