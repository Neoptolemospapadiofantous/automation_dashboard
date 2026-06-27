<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Runtime\Automation\AutomationCaller;
use App\Runtime\Automation\AutomationCatalog;
use App\Runtime\Automation\AutomationFailed;
use App\Runtime\Automation\AutomationTimeout;
use App\Runtime\Automation\BlockedAutomationUrl;
use App\Runtime\Automation\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fire-and-forget automation execution on the database queue. The agent's
 * turn already returned "kicked that off" — this performs the actual signed
 * webhook call out of band.
 *
 * Billing happened synchronously in AutomationDispatcher before this was
 * enqueued, so the job never bills. It carries only the run id + arguments;
 * the signing secret is read fresh from the agent (never serialized into the
 * jobs table).
 *
 * Idempotent: a queue retry reloads the run and no-ops if it already reached
 * a terminal state, so a retry can't double-fire the webhook.
 */
class DispatchAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $arguments,
    ) {}

    public function handle(AutomationCaller $caller, CircuitBreaker $breaker): void
    {
        $run = AutomationRun::find($this->runId);
        if ($run === null || $run->status !== AutomationRun::STATUS_PENDING) {
            return; // already done, or gone — don't re-fire.
        }

        $agent = $run->agent;
        $action = AutomationCatalog::forAgent($run->agent_id)->find($run->action);
        if ($agent === null || $action === null) {
            $run->forceFill([
                'status' => AutomationRun::STATUS_FAILED,
                'error' => 'Agent or action no longer exists.',
            ])->save();

            return;
        }

        try {
            $result = $caller->send($action, $this->arguments, (string) $agent->automation_secret);

            $run->forceFill([
                'status' => AutomationRun::STATUS_SUCCESS,
                'http_status' => $result['http_status'],
                'duration_ms' => $result['duration_ms'],
                'response' => ['body' => Str::limit($result['body'], 4000)],
            ])->save();

            $breaker->recordSuccess($run->agent_id, $action->name);
        } catch (BlockedAutomationUrl $e) {
            $run->forceFill(['status' => AutomationRun::STATUS_BLOCKED, 'error' => Str::limit($e->getMessage(), 500)])->save();
            // Bad URL — don't retry or trip the breaker.
        } catch (AutomationTimeout|AutomationFailed $e) {
            $status = $e instanceof AutomationTimeout ? AutomationRun::STATUS_TIMEOUT : AutomationRun::STATUS_FAILED;
            $breaker->recordFailure($run->agent_id, $action->name);

            // Let the queue retry transient failures; on the last attempt,
            // persist the terminal status so the row isn't stuck pending.
            if ($this->attempts() >= $this->tries) {
                $run->forceFill(['status' => $status, 'error' => Str::limit($e->getMessage(), 500)])->save();
                Log::warning('Automation job exhausted retries', ['automation_run_id' => $run->id, 'status' => $status]);

                return;
            }

            throw $e; // trigger backoff retry
        }
    }
}
