<?php

namespace App\Runtime\Automation;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Jobs\DispatchAutomationJob;
use App\Models\Agent;
use App\Models\AutomationRun;
use App\Runtime\Models\RuntimeSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates one automation invocation end to end: circuit-breaker gate →
 * audit row → billing (BEFORE firing) → execute (sync inline, or async via a
 * queued job). Returns the tool-result payload the LLM sees.
 *
 * Billing-before-firing is the money invariant: out of credits → no webhook,
 * ever. Every path writes an automation_runs row so the call is auditable and
 * feeds the #14 data layer.
 */
class AutomationDispatcher
{
    public function __construct(
        private readonly CreditMeter $credits,
        private readonly AutomationCaller $caller,
        private readonly CircuitBreaker $breaker,
        private readonly OutboundGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{status: string, message: string, run_id?: int, response?: string}
     */
    public function dispatch(Agent $agent, AutomationAction $action, array $arguments, ?RuntimeSession $session = null): array
    {
        // Breaker: a flapping endpoint is paused — don't bill or fire.
        if ($this->breaker->isOpen($agent->id, $action->name)) {
            return [
                'status' => 'paused',
                'message' => "The {$action->name} automation is temporarily paused after repeated failures. Try again shortly.",
            ];
        }

        $run = AutomationRun::create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'runtime_session_id' => $session?->id,
            'action' => $action->name,
            'mode' => $action->mode,
            'status' => AutomationRun::STATUS_PENDING,
            'idempotency_key' => (string) Str::uuid(),
            'request' => $this->trimForStorage($arguments),
        ]);

        // SSRF pre-flight BEFORE billing: a misconfigured URL is the
        // operator's fault, not the team's — don't charge for a call that can
        // never leave the box. (AutomationCaller guards again at send time as
        // defense-in-depth against DNS rebinding.)
        try {
            $this->guard->assertSafe($action->url);
        } catch (BlockedAutomationUrl $e) {
            return $this->fail($run, AutomationRun::STATUS_BLOCKED, $e->getMessage(), trip: false, agentId: $agent->id, action: $action->name);
        }

        // Bill BEFORE firing. Out of credits → no webhook leaves the box.
        try {
            $this->credits->consume($agent->team, $action->creditCost, $agent->id, [
                'reason_detail' => 'automation',
                'action' => $action->name,
                'automation_run_id' => $run->id,
            ]);
            $run->forceFill(['credits_charged' => $action->creditCost])->save();
        } catch (OutOfCredits) {
            $run->forceFill(['status' => AutomationRun::STATUS_OUT_OF_CREDITS])->save();

            return [
                'status' => 'error',
                'message' => 'This action needs more credits than the team currently has. Nothing was run.',
            ];
        }

        if ($action->isAsync()) {
            DispatchAutomationJob::dispatch($run->id, $arguments);

            return [
                'status' => 'accepted',
                'run_id' => $run->id,
                'message' => "Kicked off the {$action->name} automation — it's running in the background.",
            ];
        }

        return $this->runSync($agent, $action, $arguments, $run);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{status: string, message: string, run_id: int, response?: string}
     */
    private function runSync(Agent $agent, AutomationAction $action, array $arguments, AutomationRun $run): array
    {
        try {
            $result = $this->caller->send($action, $arguments, (string) $agent->automation_secret);

            $run->forceFill([
                'status' => AutomationRun::STATUS_SUCCESS,
                'http_status' => $result['http_status'],
                'duration_ms' => $result['duration_ms'],
                'response' => $this->trimForStorage(['body' => $result['body']]),
            ])->save();

            $this->breaker->recordSuccess($agent->id, $action->name);

            return [
                'status' => 'ok',
                'run_id' => $run->id,
                'message' => "The {$action->name} automation ran successfully.",
                'response' => $result['body'],
            ];
        } catch (BlockedAutomationUrl $e) {
            // Misconfiguration, not endpoint flapping — record but DON'T trip
            // the breaker (retrying won't help a bad URL).
            return $this->fail($run, AutomationRun::STATUS_BLOCKED, $e->getMessage(), trip: false, agentId: $agent->id, action: $action->name);
        } catch (AutomationTimeout $e) {
            return $this->fail($run, AutomationRun::STATUS_TIMEOUT, $e->getMessage(), trip: true, agentId: $agent->id, action: $action->name);
        } catch (AutomationFailed $e) {
            $run->forceFill(['http_status' => $e->httpStatus])->save();

            return $this->fail($run, AutomationRun::STATUS_FAILED, $e->getMessage(), trip: true, agentId: $agent->id, action: $action->name);
        }
    }

    /**
     * @return array{status: string, message: string, run_id: int}
     */
    private function fail(AutomationRun $run, string $status, string $error, bool $trip, int $agentId, string $action): array
    {
        $run->forceFill([
            'status' => $status,
            'error' => Str::limit($error, 500),
        ])->save();

        if ($trip) {
            $this->breaker->recordFailure($agentId, $action);
        }

        Log::warning('Automation call failed', [
            'automation_run_id' => $run->id,
            'agent_id' => $agentId,
            'action' => $action,
            'status' => $status,
        ]);

        return [
            'status' => 'error',
            'run_id' => $run->id,
            'message' => "The {$action} automation didn't complete ({$status}). A teammate can follow up.",
        ];
    }

    /**
     * Cap a payload before persisting it as JSON, so a huge arg blob or
     * response can't bloat the audit table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function trimForStorage(array $data): array
    {
        $encoded = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) <= 8192) {
            return $data;
        }

        return ['_truncated' => true, 'preview' => substr($encoded, 0, 8192)];
    }
}
