<?php

namespace Tests\Snapshots;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Contracts\Runtime;
use App\Runtime\LLM\SystemPrompt;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Snapshot-style fixture tests for the critical dialog paths through the
 * REAL engine (app(Runtime::class) → FlowExecutor → LeadCaptureFlow),
 * with Anthropic faked at the HTTP layer and fixed inputs.
 *
 * Each path's full trace + state outcome is compared against a committed
 * JSON fixture (tests/Snapshots/fixtures/*.json). Any drift in trace
 * shape, flow transitions, lead persistence, or KB grounding fails the
 * suite with a structural diff.
 *
 * Regenerating after an INTENTIONAL behavior change:
 *   REGENERATE_SNAPSHOTS=1 php artisan test --filter=Snapshots   # rewrites fixtures, marks skipped
 *   php artisan test --filter=Snapshots                          # verify green, commit the diff
 */
class DialogPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'runtime.llm.anthropic.api_key' => 'sk-anthropic-test',
            'runtime.embeddings.openai_api_key' => 'sk-openai-test',
            'runtime.embeddings.dimensions' => 4, // tiny fake vectors
        ]);

        Notification::fake(); // request_handoff notifies the team owner
    }

    public function test_path1_greeting_launch_matches_fixture(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->textResponse('Hi there! What brings you to us today?'),
            ),
        ]);

        $agent = $this->agent();

        $traces = app(Runtime::class)->launch($agent, 'visitor-snap-1');

        $this->assertMatchesJsonFixture('path1_greeting', [
            'traces' => $traces,
            'flow_state' => $this->runtimeSession($agent, 'visitor-snap-1')->flow_state,
        ]);
    }

    public function test_path2_lead_capture_matches_fixture(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolUseResponse('toolu_snap_lead', 'capture_lead', [
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'company' => 'Analytical Engines Ltd',
                    'notes' => 'Wants the Pro plan for her team',
                    'score' => 90,
                ]))
                ->push($this->textResponse('All set, Ada — our team will reach out shortly!')),
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'visitor-snap-2', 'discovery');

        $traces = app(Runtime::class)->sendText($agent, 'visitor-snap-2', 'I am Ada Lovelace, ada@example.com — sign me up');

        $lead = Lead::query()->firstOrFail();

        $this->assertMatchesJsonFixture('path2_lead_capture', [
            'traces' => $traces,
            'flow_state' => $this->runtimeSession($agent, 'visitor-snap-2')->flow_state, // capture_lead success → wrapup
            'lead' => [
                // Row shape only — id/timestamps are volatile and stay out.
                'name' => $lead->name,
                'email' => $lead->email,
                'score' => (int) $lead->score,
                'captured' => (array) $lead->captured,
            ],
        ]);
    }

    public function test_path3_kb_grounded_answer_matches_fixture(): void
    {
        Http::fake([
            // Embeddings (ingest + query): fixed 4-dim unit vector per input.
            'api.openai.com/*' => function (Request $request) {
                $data = [];
                foreach (array_values((array) $request->data()['input']) as $i => $text) {
                    $data[] = ['index' => $i, 'embedding' => [1.0, 0.0, 0.0, 0.0]];
                }

                return Http::response(['data' => $data]);
            },
            'api.anthropic.com/*' => Http::response(
                $this->textResponse('The Starter plan is $99 per month.'),
            ),
        ]);

        $agent = $this->agent();
        app(KnowledgeStore::class)->ingestDocument($agent->id, 'Pricing', 'Starter plan costs $99 per month.', ['source' => 'text']);
        $this->seedSession($agent, 'visitor-snap-3', 'discovery');

        $traces = app(Runtime::class)->sendText($agent, 'visitor-snap-3', 'how much does the starter plan cost?');

        // The auto-RAG pipeline must ground the turn: the outbound Anthropic
        // system prompt carries the ingested chunk text verbatim.
        $system = $this->recordedAnthropicSystemPrompt();
        $this->assertStringContainsString('Starter plan costs $99 per month.', $system);

        $this->assertMatchesJsonFixture('path3_kb_grounded', [
            'traces' => $traces,
            'flow_state' => $this->runtimeSession($agent, 'visitor-snap-3')->flow_state, // stays in discovery
            'system_prompt_contains_chunk' => str_contains($system, 'Starter plan costs $99 per month.'),
        ]);
    }

    public function test_path4_handoff_then_end_session_matches_fixture(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                // Turn 1 (discovery): escalate to a human, then reassure.
                ->push($this->toolUseResponse('toolu_snap_handoff', 'request_handoff', [
                    'reason' => 'Visitor asked to speak with a human',
                ]))
                ->push($this->textResponse('Of course — a teammate will reach out shortly. Anything else?'))
                // Turn 2 (wrapup): close the session, then say goodbye.
                ->push($this->toolUseResponse('toolu_snap_end', 'end_session', [
                    'reason' => 'visitor said goodbye',
                ]))
                ->push($this->textResponse('Thanks for stopping by — goodbye!')),
        ]);

        $agent = $this->agent();
        $this->seedSession($agent, 'visitor-snap-4', 'discovery');

        $runtime = app(Runtime::class);

        $turn1 = $runtime->sendText($agent, 'visitor-snap-4', 'I want to talk to a human please');

        // request_handoff has no transition — the session stays in discovery.
        // end_session is only armed in wrapup, so move there (the state a
        // real conversation reaches once capture_lead succeeds).
        $this->runtimeSession($agent, 'visitor-snap-4')->forceFill(['flow_state' => 'wrapup'])->save();

        $turn2 = $runtime->sendText($agent, 'visitor-snap-4', 'that is everything, goodbye');

        $session = $this->runtimeSession($agent, 'visitor-snap-4');

        $this->assertMatchesJsonFixture('path4_handoff_end', [
            'turn1_traces' => $turn1,
            'turn2_traces' => $turn2,
            'final_state' => $session->flow_state, // end_session success → ended
            // Visible variables only — '_'-prefixed token counters stay out.
            'variables' => array_filter(
                (array) $session->variables,
                fn ($key) => ! str_starts_with((string) $key, '_'),
                ARRAY_FILTER_USE_KEY,
            ),
        ]);
    }

    // ── snapshot mechanism ───────────────────────────────────────────────────

    /**
     * Volatile keys stripped recursively before fixtures are written or
     * compared — database ids, timestamps, and uuids change run to run.
     */
    private const VOLATILE_KEYS = [
        'id', 'uuid', 'lead_id', 'agent_id', 'team_id', 'session_id', 'document_id',
        'created_at', 'updated_at', 'published_at', 'last_activity_at', 'date', 'timestamp',
    ];

    /**
     * Compare $actual against the committed fixture. With
     * REGENERATE_SNAPSHOTS=1 the fixture is rewritten instead and the test
     * is marked skipped so a regenerate run can never report a false green.
     *
     * @param  array<string, mixed>  $actual
     */
    private function assertMatchesJsonFixture(string $name, array $actual): void
    {
        $actual = $this->normalizeVolatile($actual);
        $path = __DIR__.'/fixtures/'.$name.'.json';

        if ((string) env('REGENERATE_SNAPSHOTS') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents(
                $path,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );

            $this->markTestSkipped("regenerated fixture {$name}.json — rerun without REGENERATE_SNAPSHOTS to verify");
        }

        $this->assertFileExists($path, "Missing fixture {$name}.json — run once with REGENERATE_SNAPSHOTS=1 to create it, then commit it.");

        $expected = json_decode((string) file_get_contents($path), true);

        $this->assertEquals($expected, $actual, "Dialog path '{$name}' diverged from its committed fixture.");
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function normalizeVolatile(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::VOLATILE_KEYS, true)) {
                unset($value[$key]);

                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->normalizeVolatile($item);
            }
        }

        return $value;
    }

    // ── fixtures + helpers ───────────────────────────────────────────────────

    private function agent(): Agent
    {
        $user = User::factory()->withPersonalTeam()->create();

        return Agent::factory()->for($user->currentTeam)->create([
            'runtime_mode' => 'native',
            'status' => 'active',
        ]);
    }

    private function seedSession(Agent $agent, string $visitorId, string $state): void
    {
        RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitorId,
            'flow_state' => $state,
            'variables' => [],
            'history' => [],
        ]);
    }

    private function runtimeSession(Agent $agent, string $visitorId): RuntimeSession
    {
        return RuntimeSession::query()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $visitorId)
            ->firstOrFail();
    }

    private function recordedAnthropicSystemPrompt(): string
    {
        $system = '';
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                // system is now cacheable blocks (SystemPrompt::blocks); flatten.
                $system = SystemPrompt::toText($request->data()['system'] ?? '');
            }
        }

        return $system;
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    /**
     * Fixed tool_use ids keep the snapshot inputs fully deterministic.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toolUseResponse(string $id, string $tool, array $input): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => 'One moment…'],
                ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 25],
        ];
    }
}
