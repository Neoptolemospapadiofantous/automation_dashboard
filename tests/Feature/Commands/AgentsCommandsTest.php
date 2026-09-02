<?php

namespace Tests\Feature\Commands;

use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two CLI surfaces on the runtime: agents:terminal (single agent) and
 * agents:collab (multi-agent round-table). Flowstack Core — the engine every
 * plan includes — is faked at the HTTP layer so no turn hits the network.
 */
class AgentsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.embeddings.openai_api_key' => 'sk-openai-test']);
        $this->fakeCore([['text' => 'CANNED REPLY', 'in' => 10, 'out' => 5]]);
    }

    protected function tearDown(): void
    {
        foreach (glob(base_path('data/agents/rooms/phpunit-*.json')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_terminal_runs_one_shot_message_and_bills(): void
    {
        $agent = $this->currentAgent(100);

        $this->artisan('agents:terminal', [
            '--team' => $agent->team_id,
            '--agent' => $agent->id,
            '--visitor' => 'phpunit-term',
            '--message' => 'hello',
        ])->assertExitCode(0);

        $this->assertTrue($agent->team->fresh()->credit_balance < 100);
    }

    public function test_terminal_no_bill_leaves_credits_untouched(): void
    {
        $agent = $this->currentAgent(100);

        $this->artisan('agents:terminal', [
            '--team' => $agent->team_id,
            '--agent' => $agent->id,
            '--visitor' => 'phpunit-term2',
            '--message' => 'hello',
            '--no-bill' => true,
        ])->assertExitCode(0);

        $this->assertSame(100, $agent->team->fresh()->credit_balance);
    }

    public function test_collab_round_table_runs_and_writes_ledger(): void
    {
        $a = $this->currentAgent(100);
        $b = $this->currentAgent(100);
        $room = 'phpunit-'.uniqid();

        $this->artisan('agents:collab', [
            '--agents' => $a->id.','.$b->id,
            '--topic' => 'plan a feature',
            '--rounds' => 1,
            '--room' => $room,
            '--no-bill' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(base_path('data/agents/rooms/'.$room.'.json'));
    }

    public function test_collab_rejects_unknown_agent(): void
    {
        $this->artisan('agents:collab', [
            '--agents' => 'does-not-exist',
            '--topic' => 'x',
            '--no-bill' => true,
        ])->assertExitCode(1);
    }

    public function test_ingest_project_targets_an_existing_agent_by_slug(): void
    {
        // Fake OpenAI embeddings so ingestion never hits the network.
        config(['runtime.embeddings.dimensions' => 4]);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'x']], 'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
            'api.openai.com/v1/embeddings' => function ($request) {
                $data = [];
                foreach (array_values((array) $request->data()['input']) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [1.0, 0.0, 0.0, 0.0]];
                }

                return Http::response(['data' => $data]);
            },
        ]);

        $agent = $this->currentAgent(100);

        $dir = sys_get_temp_dir().'/kb-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/pricing.md', "# Pricing\n\nStarter is EUR 99 per month.");

        try {
            $this->artisan('agents:ingest-project', [
                'path' => $dir,
                '--agent' => $agent->slug,
            ])->assertExitCode(0);
        } finally {
            @unlink($dir.'/pricing.md');
            @rmdir($dir);
        }

        $kb = app(KnowledgeStore::class);
        $this->assertNotEmpty($kb->listDocuments($agent->id));
    }

    public function test_ingest_project_replaces_changed_docs_instead_of_duplicating(): void
    {
        config(['runtime.embeddings.dimensions' => 4]);
        Http::fake([
            'api.openai.com/v1/embeddings' => function ($request) {
                $data = [];
                foreach (array_values((array) $request->data()['input']) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [1.0, 0.0, 0.0, 0.0]];
                }

                return Http::response(['data' => $data]);
            },
        ]);

        $agent = $this->currentAgent(100);

        $dir = sys_get_temp_dir().'/kb-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/overview.md', "# Overview\n\nOld chat-led positioning.");

        try {
            $this->artisan('agents:ingest-project', ['path' => $dir, '--agent' => $agent->slug])
                ->assertExitCode(0);

            // Edit the doc and re-ingest — the changed content must REPLACE
            // the old document (replace-by-source), not sit beside it while
            // the stale version keeps winning retrieval.
            file_put_contents($dir.'/overview.md', "# Overview\n\nNew delegation-led positioning.");
            $this->artisan('agents:ingest-project', ['path' => $dir, '--agent' => $agent->slug])
                ->assertExitCode(0);
        } finally {
            @unlink($dir.'/overview.md');
            @rmdir($dir);
        }

        $kb = app(KnowledgeStore::class);
        $docs = $kb->listDocuments($agent->id);
        $this->assertCount(1, $docs);
        $this->assertStringContainsString('delegation-led', KbDocument::findOrFail($docs[0]['id'])->raw_content);
    }

    public function test_ingest_project_errors_on_unknown_agent(): void
    {
        $dir = sys_get_temp_dir().'/kb-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/x.md', '# X');

        try {
            $this->artisan('agents:ingest-project', [
                'path' => $dir,
                '--agent' => 'no-such-agent-slug',
            ])->assertExitCode(1);
        } finally {
            @unlink($dir.'/x.md');
            @rmdir($dir);
        }
    }

    private function currentAgent(int $balance): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['status' => 'active']);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id, 'credit_balance' => $balance])->save();

        return $agent->fresh()->load('team');
    }
}
