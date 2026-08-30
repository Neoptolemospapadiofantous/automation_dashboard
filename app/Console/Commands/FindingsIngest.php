<?php

namespace App\Console\Commands;

use App\Support\Findings\FindingsStore;
use Illuminate\Console\Command;

/**
 * Ingest the bash agents' findings.json files into the agent_findings
 * table, so the scripts stay untouched and the record survives deploys.
 * providers:health-check writes to the store directly; its file is picked
 * up here too, which is harmless (idempotent on collector + ts).
 */
class FindingsIngest extends Command
{
    protected $signature = 'findings:ingest {--path= : Directory holding <collector>/findings.json (default data/agents)}';

    protected $description = 'Persist data/agents/*/findings.json into agent_findings and prune old runs.';

    public function handle(FindingsStore $store): int
    {
        $base = (string) ($this->option('path') ?: base_path('data/agents'));
        $ingested = 0;

        foreach (FindingsStore::COLLECTORS as $collector) {
            $file = $base.'/'.$collector.'/findings.json';
            if (! is_file($file)) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($file), true);
            if (! is_array($payload)) {
                $this->warn("{$collector}: findings.json is not valid JSON — skipped");

                continue;
            }
            if ($store->record($collector, $payload)) {
                $ingested++;
                $this->line("{$collector}: recorded {$payload['ts']} ({$payload['overall']})");
            }
        }

        $pruned = $store->prune();
        $this->info("Ingested {$ingested} run(s), pruned {$pruned}.");

        return self::SUCCESS;
    }
}
