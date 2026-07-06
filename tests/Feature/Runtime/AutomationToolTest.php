<?php

namespace Tests\Feature\Runtime;

use App\Jobs\DispatchAutomationJob;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\AutomationRun;
use App\Models\User;
use App\Runtime\Automation\OutboundGuard;
use App\Runtime\Automation\WebhookSigner;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Contract for the agent → n8n automation tool, exercised through the real
 * ToolRegistry against a FAKE n8n endpoint (Http::fake) — no live calls.
 *
 * The SSRF guard's DNS resolver is stubbed per-test so host resolution is
 * deterministic and offline; everything else (billing, audit row, signing,
 * queueing, feature flag) runs for real.
 */
class AutomationToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'runtime.automation.enabled' => true,
            'runtime.automation.allow_private_hosts' => false,
        ]);
    }

    public function test_call_automation_is_registered(): void
    {
        $specs = app(ToolRegistry::class)->specs(['call_automation']);

        $this->assertCount(1, $specs);
        $this->assertSame('call_automation', $specs[0]['name']);
        $this->assertArrayHasKey('action', $specs[0]['input_schema']['properties']);
    }

    public function test_sync_call_bills_signs_records_and_returns_response(): void
    {
        $this->stubResolverPublic();
        Http::fake([
            'n8n.flowstack.run/*' => Http::response(['ok' => true, 'order' => 'A-100'], 200),
        ]);

        $context = $this->context([
            ['id' => '01JZLOOKUPORDER00000000000', 'name' => 'lookup_order', 'description' => 'Look up an order', 'url' => 'https://n8n.flowstack.run/webhook/orders', 'mode' => 'sync', 'credit_cost' => 3],
        ]);
        $startBalance = $context->agent->team->totalCredits();

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'lookup_order', 'arguments' => ['id' => 100]]),
            $context,
        );

        $this->assertFalse($out['is_error']);
        $this->assertStringContainsString('A-100', $out['content']);

        // Audit row written and marked success with the upstream status. The
        // stable config id is recorded so the run stays linked across renames.
        $this->assertDatabaseHas('automation_runs', [
            'agent_id' => $context->agent->id,
            'action' => 'lookup_order',
            'action_id' => '01JZLOOKUPORDER00000000000',
            'mode' => 'sync',
            'status' => AutomationRun::STATUS_SUCCESS,
            'http_status' => 200,
            'credits_charged' => 3,
        ]);

        // Billed exactly the action's cost.
        $this->assertSame($startBalance - 3, $context->agent->team->fresh()->totalCredits());

        // The request was signed with the agent's secret.
        Http::assertSent(function ($request) use ($context): bool {
            $ts = $request->header(WebhookSigner::TIMESTAMP_HEADER)[0] ?? '';
            $sig = $request->header(WebhookSigner::SIGNATURE_HEADER)[0] ?? '';
            $expected = 'sha256='.(new WebhookSigner)->sign((string) $context->agent->automation_secret, $ts, $request->body());

            return $ts !== '' && hash_equals($expected, $sig);
        });
    }

    public function test_ssrf_blocked_url_never_sends_and_is_not_billed(): void
    {
        // Host resolves to a private address → guard must block.
        $this->stubResolver(['10.0.0.5']);
        Http::fake();

        $context = $this->context([
            ['name' => 'evil', 'description' => 'x', 'url' => 'https://internal.evil/webhook', 'mode' => 'sync', 'credit_cost' => 3],
        ]);
        $startBalance = $context->agent->team->totalCredits();

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'evil', 'arguments' => []]),
            $context,
        );

        $this->assertStringContainsString('blocked', $out['content']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('automation_runs', [
            'agent_id' => $context->agent->id,
            'status' => AutomationRun::STATUS_BLOCKED,
            'credits_charged' => 0,
        ]);
        // Misconfiguration must not cost the team a credit.
        $this->assertSame($startBalance, $context->agent->team->fresh()->totalCredits());
    }

    public function test_redirect_response_fails_and_is_never_followed(): void
    {
        // A vetted public endpoint answering 3xx must be treated as a failure:
        // following it would re-run the request against a URL the SSRF guard
        // never saw (here: the cloud metadata endpoint).
        $this->stubResolverPublic();
        Http::fake([
            'n8n.flowstack.run/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('should never be reached', 200),
        ]);

        $context = $this->context([
            ['name' => 'redirector', 'description' => 'x', 'url' => 'https://n8n.flowstack.run/webhook/redirect', 'mode' => 'sync', 'credit_cost' => 3],
        ]);

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'redirector', 'arguments' => []]),
            $context,
        );

        $this->assertStringContainsString('failed', $out['content']);

        // Exactly one request — to the original URL, never the Location target.
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));

        $this->assertDatabaseHas('automation_runs', [
            'agent_id' => $context->agent->id,
            'action' => 'redirector',
            'status' => AutomationRun::STATUS_FAILED,
            'http_status' => 302,
        ]);
    }

    public function test_out_of_credits_never_sends(): void
    {
        $this->stubResolverPublic();
        Http::fake();

        $context = $this->context(
            [['name' => 'lookup', 'description' => 'x', 'url' => 'https://n8n.flowstack.run/webhook/x', 'mode' => 'sync', 'credit_cost' => 3]],
            credits: 0,
        );

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'lookup', 'arguments' => []]),
            $context,
        );

        $this->assertStringContainsString('credits', $out['content']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('automation_runs', [
            'agent_id' => $context->agent->id,
            'status' => AutomationRun::STATUS_OUT_OF_CREDITS,
        ]);
    }

    public function test_async_action_queues_job_and_returns_immediately(): void
    {
        $this->stubResolverPublic();
        Queue::fake();

        $context = $this->context([
            ['name' => 'sync_crm', 'description' => 'x', 'url' => 'https://n8n.flowstack.run/webhook/crm', 'mode' => 'async', 'credit_cost' => 1],
        ]);

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'sync_crm', 'arguments' => []]),
            $context,
        );

        $this->assertFalse($out['is_error']);
        Queue::assertPushed(DispatchAutomationJob::class);
        $this->assertDatabaseHas('automation_runs', [
            'action' => 'sync_crm',
            'mode' => 'async',
            'status' => AutomationRun::STATUS_PENDING,
        ]);
    }

    public function test_unknown_action_returns_error_without_run(): void
    {
        $context = $this->context([
            ['name' => 'real', 'description' => 'x', 'url' => 'https://n8n.flowstack.run/webhook/x', 'mode' => 'sync'],
        ]);

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'ghost', 'arguments' => []]),
            $context,
        );

        $this->assertStringContainsString('no automation named', $out['content']);
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_disabled_feature_flag_blocks_everything(): void
    {
        config(['runtime.automation.enabled' => false]);
        Http::fake();

        $context = $this->context([
            ['name' => 'real', 'description' => 'x', 'url' => 'https://n8n.flowstack.run/webhook/x', 'mode' => 'sync'],
        ]);

        $out = app(ToolRegistry::class)->dispatch(
            new ToolCall('t1', 'call_automation', ['action' => 'real', 'arguments' => []]),
            $context,
        );

        $this->assertStringContainsString('not enabled', $out['content']);
        Http::assertNothingSent();
        $this->assertSame(0, AutomationRun::count());
    }

    /**
     * Build a context whose agent has the given automations PUBLISHED.
     *
     * @param  list<array<string, mixed>>  $automations
     */
    private function context(array $automations, int $credits = 100): ConversationContext
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->currentTeam->forceFill(['credit_balance' => $credits, 'topup_balance' => 0])->save();
        $agent = Agent::factory()->for($user->currentTeam)->create(['runtime_mode' => 'native']);

        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => AgentConfigVersion::STATUS_PUBLISHED,
            'config' => ['automations' => $automations],
            'published_at' => now(),
        ]);

        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'v-'.uniqid(),
            'flow_state' => 'discovery',
            'variables' => [],
            'history' => [],
        ]);

        return new ConversationContext($agent->fresh(), $session, 'test message');
    }

    /** Stub the SSRF guard's DNS resolver to a fixed public address. */
    private function stubResolverPublic(): void
    {
        $this->stubResolver(['93.184.216.34']);
    }

    /** @param list<string> $ips */
    private function stubResolver(array $ips): void
    {
        $this->app->bind(OutboundGuard::class, fn (): OutboundGuard => new OutboundGuard(fn (string $host): array => $ips));
    }
}
