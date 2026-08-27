<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use Illuminate\Console\Command;

/**
 * Repoint an agent's published quality tier by slug — e.g. move a
 * sonnet-pinned agent onto gemini when the Anthropic tier is unfunded.
 * Edits the current PUBLISHED config version in place (the engine reads
 * the published version), leaving instructions/knowledge untouched.
 *
 * Ops tool: run on the box (or via Forge Commands) — prod has no direct
 * DB access from the dev machines.
 */
class SetAgentTier extends Command
{
    protected $signature = 'agents:set-tier {slug} {tier}';

    protected $description = "Change a published agent's model_tier by slug.";

    public function handle(): int
    {
        $tier = (string) $this->argument('tier');
        if (! array_key_exists($tier, (array) config('runtime.tiers'))) {
            $this->error("Unknown tier '{$tier}'. Valid: ".implode(', ', array_keys((array) config('runtime.tiers'))));

            return self::FAILURE;
        }

        $agent = Agent::query()->where('slug', $this->argument('slug'))->first();
        if (! $agent instanceof Agent) {
            $this->error("No agent with slug '{$this->argument('slug')}'.");

            return self::FAILURE;
        }

        $version = AgentConfigVersion::query()
            ->where('agent_id', $agent->id)
            ->where('status', AgentConfigVersion::STATUS_PUBLISHED)
            ->first();
        if (! $version instanceof AgentConfigVersion) {
            $this->error("Agent '{$agent->name}' has no published config to edit.");

            return self::FAILURE;
        }

        $config = (array) $version->config;
        $before = (string) ($config['model_tier'] ?? '(default)');
        $config['model_tier'] = $tier;
        $version->update(['config' => $config]);

        $this->info(sprintf(
            '%s (%s): model_tier %s -> %s. Effective tier now: %s.',
            $agent->name,
            $agent->slug,
            $before,
            $tier,
            AgentConfigVersion::publishedTier($agent->id),
        ));

        return self::SUCCESS;
    }
}
