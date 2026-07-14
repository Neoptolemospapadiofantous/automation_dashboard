<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbDocument;
use App\Support\PublicWebPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-ingest the website a customer gave us during onboarding, so their
 * agent can answer real product questions from its very first conversation
 * instead of opening on an empty knowledge base.
 *
 * Best-effort by design: a bad URL, an unreachable site, or a disabled
 * embeddings key just leaves the KB empty (the Knowledge page still offers
 * manual ingestion) — onboarding itself never blocks or fails on this.
 *
 * Idempotent: a queue retry (or an onboarding double-submit) no-ops when the
 * URL was already ingested for this agent.
 */
class IngestOnboardingWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $agentId,
        public string $url,
    ) {}

    /**
     * Seconds to wait before each retry (read by the queue worker).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Onboarding website ingest failed permanently', [
            'agent_id' => $this->agentId,
            'url' => $this->url,
            'tries' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(KnowledgeStore $knowledge, PublicWebPage $page): void
    {
        if ((string) config('runtime.embeddings.openai_api_key') === '') {
            return; // Embeddings disabled — nowhere to ingest into.
        }

        $agent = Agent::query()->find($this->agentId);
        if ($agent === null) {
            return;
        }

        $alreadyIngested = KbDocument::query()
            ->where('agent_id', $agent->id)
            ->where('metadata->source_url', $this->url)
            ->exists();
        if ($alreadyIngested) {
            return;
        }

        try {
            $content = $page->fetchText($this->url);
        } catch (\RuntimeException $e) {
            // User-recoverable fetch failure (non-public host, HTTP error,
            // no readable text) — permanent for this URL, don't retry.
            Log::info('Onboarding website ingest skipped', [
                'agent_id' => $agent->id,
                'url' => $this->url,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        // Embedding/DB failures throw past here so the queue retries them.
        $knowledge->ingestDocument($agent->id, $this->url, $content, [
            'source' => 'url',
            'source_url' => $this->url,
        ]);
    }
}
