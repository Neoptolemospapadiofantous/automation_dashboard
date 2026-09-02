<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Billing\OwnKey;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Runtime\LLM\LlmRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Versioned agent behavior — the native successor to the old Environments
 * page, rebuilt around the job it actually did: stage a change, publish
 * it to live traffic, keep history, roll back.
 *
 * Edit the DRAFT (instructions + greeting guidance) → Publish makes it
 * the live version the engine injects into every turn → previous live
 * version is archived and restorable. Export downloads any version as
 * JSON.
 *
 * Invariant: at most one draft + one published per agent (enforced in
 * the publish/save transactions, not by schema — versions are append-
 * mostly and tiny).
 */
class AgentVersionsController extends Controller
{
    use AuthorizesByTeamRole;

    public function index(Request $request): Response
    {
        $agent = $this->currentAgent($request);

        $versions = [];
        $draft = null;
        if ($agent !== null) {
            foreach (AgentConfigVersion::query()
                ->where('agent_id', $agent->id)
                ->orderByDesc('version')
                ->get() as $v) {
                $versions[] = [
                    'version' => $v->version,
                    'status' => $v->status,
                    'config' => $v->config,
                    'published_at' => $v->published_at?->toIso8601String(),
                    'updated_at' => $v->updated_at->toIso8601String(),
                ];
                if ($v->status === AgentConfigVersion::STATUS_DRAFT) {
                    $draft = $v->config;
                }
            }
        }

        // BYOK: a tier whose provider matches a usable team key (and the
        // team is under its monthly cap) costs no credits — the tile says so
        // instead of quoting a price nobody on that key would pay.
        $ownKey = app(OwnKey::class);
        $byokTeam = $request->user()?->currentTeam;

        $tiers = [];
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            $provider = (string) ($tier['provider'] ?? 'anthropic');
            $tiers[] = [
                'key' => $key,
                'label' => (string) ($tier['label'] ?? ucfirst($key)),
                'description' => (string) ($tier['description'] ?? ''),
                'model' => (string) ($tier['model'] ?? ''),
                'provider' => $provider,
                'credits_per_message' => (int) ($tier['credits_per_message'] ?? 1),
                // Greyed out in the UI until the provider's API key is set.
                'available' => LlmRouter::providerAvailable($provider) || ($tier['byok_only'] ?? false),
                'own_key' => $byokTeam instanceof Team
                    && $ownKey->keyFor($byokTeam, $provider) !== null
                    && $ownKey->withinCap($byokTeam),
                // Premium engines are BYOK-only: platform credits never buy
                // them, so a team without a usable key for this provider
                // cannot select the tier at all.
                'byok_only' => (bool) ($tier['byok_only'] ?? false),
                'selectable' => ($tier['byok_only'] ?? false)
                    ? ($byokTeam instanceof Team && $ownKey->keyFor($byokTeam, $provider) !== null)
                    : LlmRouter::providerAvailable($provider),
            ];
        }

        return Inertia::render('Agents/Versions', [
            'tiers' => $tiers,
            'versions' => $versions,
            'draft' => $draft, // null = no draft yet; editor starts from published or blank
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name, 'slug' => $agent->slug] : null,
        ]);
    }

    /**
     * Create-or-update the draft. Saving never touches the live version.
     */
    public function saveDraft(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'edit agent behavior');
        $agent = $this->currentAgentOrAbort($request);

        // Merge-preserving: only the behavior keys are patched, so a draft's
        // staged automations (authored on the Actions page) survive a
        // behavior save and vice versa.
        AgentConfigVersion::patchDraft($agent->id, $this->validateConfig($request));

        return back();
    }

    /**
     * Draft → live. The previously-published version is archived (it
     * stays in history and can be restored). The engine picks the new
     * config up on the very next turn — no deploy, no cache.
     */
    public function publish(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'publish agent behavior');
        $agent = $this->currentAgentOrAbort($request);

        $published = DB::transaction(function () use ($agent): bool {
            $draft = AgentConfigVersion::query()
                ->where('agent_id', $agent->id)
                ->where('status', AgentConfigVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return false;
            }

            AgentConfigVersion::query()
                ->where('agent_id', $agent->id)
                ->where('status', AgentConfigVersion::STATUS_PUBLISHED)
                ->update(['status' => AgentConfigVersion::STATUS_ARCHIVED]);

            $draft->update([
                'status' => AgentConfigVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return true;
        });

        if (! $published) {
            return back()->withErrors(['publish' => 'Nothing to publish — save a draft first.']);
        }

        return back();
    }

    /**
     * Copy an archived (or the published) version's config into the
     * draft — the rollback path. Publishing the restored draft makes it
     * live again as a NEW version (history stays linear, never rewritten).
     */
    public function restore(Request $request, int $version): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'restore agent behavior versions');
        $agent = $this->currentAgentOrAbort($request);

        $source = AgentConfigVersion::query()
            ->where('agent_id', $agent->id)
            ->where('version', $version)
            ->firstOrFail();

        DB::transaction(function () use ($agent, $source): void {
            $draft = AgentConfigVersion::query()
                ->where('agent_id', $agent->id)
                ->where('status', AgentConfigVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if ($draft) {
                $draft->update(['config' => $source->config]);

                return;
            }

            AgentConfigVersion::create([
                'agent_id' => $agent->id,
                'version' => $this->nextVersion($agent->id),
                'status' => AgentConfigVersion::STATUS_DRAFT,
                'config' => $source->config,
            ]);
        });

        return back();
    }

    /**
     * Download one version's config as JSON (the old page's "export
     * JSON" job — useful for support tickets and cross-agent copying).
     */
    public function export(Request $request, int $version): JsonResponse
    {
        $agent = $this->currentAgentOrAbort($request);

        $row = AgentConfigVersion::query()
            ->where('agent_id', $agent->id)
            ->where('version', $version)
            ->firstOrFail();

        return response()->json([
            'agent' => $agent->slug,
            'version' => $row->version,
            'status' => $row->status,
            'published_at' => $row->published_at?->toIso8601String(),
            'config' => $row->config,
        ], 200, [
            'Content-Disposition' => sprintf('attachment; filename="agent-%s-v%d.json"', $agent->slug, $row->version),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{instructions: string, greeting: string, model_tier: string}
     */
    protected function validateConfig(Request $request): array
    {
        // Selectable = the provider is configured AND, for a premium
        // (BYOK-only) engine, this team holds a usable key for it. Platform
        // credits never buy Claude or Gemini, so offering them to a team
        // without a key would sell something we will not run.
        $ownKey = app(OwnKey::class);
        $team = $request->user()?->currentTeam;
        $tierKeys = [];
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            $provider = (string) ($tier['provider'] ?? 'anthropic');
            if (($tier['byok_only'] ?? false)) {
                // Reached with the team's own key; the platform's own key is
                // irrelevant (and in production, absent).
                if (! ($team instanceof Team && $ownKey->keyFor($team, $provider) !== null)) {
                    continue;
                }
            } elseif (! LlmRouter::providerAvailable($provider)) {
                continue;
            }
            $tierKeys[] = $key;
        }

        $data = $request->validate([
            'instructions' => ['nullable', 'string', 'max:4000'],
            'greeting' => ['nullable', 'string', 'max:500'],
            'model_tier' => ['nullable', 'string', 'in:'.implode(',', $tierKeys)],
        ]);

        return [
            'instructions' => trim((string) ($data['instructions'] ?? '')),
            'greeting' => trim((string) ($data['greeting'] ?? '')),
            'model_tier' => (string) ($data['model_tier'] ?? AgentConfigVersion::defaultTier()),
        ];
    }

    protected function nextVersion(int $agentId): int
    {
        return (int) AgentConfigVersion::query()
            ->where('agent_id', $agentId)
            ->max('version') + 1;
    }

    protected function currentAgent(Request $request): ?Agent
    {
        $team = $request->user()?->currentTeam;
        if (! $team instanceof Team) {
            return null;
        }

        $agent = $team->currentAgent;

        return $agent instanceof Agent ? $agent : null;
    }

    protected function currentAgentOrAbort(Request $request): Agent
    {
        $agent = $this->currentAgent($request);
        abort_if($agent === null, 503, 'No agent is set up yet.');

        return $agent;
    }
}
