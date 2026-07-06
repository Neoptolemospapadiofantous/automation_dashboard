<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Http\Controllers\Concerns\AuthorizesHermesOperator;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\AutomationRun;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator "Actions" UI — author the n8n automations an agent can invoke
 * mid-conversation (phase-16). Writes into the SAME draft config the
 * behavior editor uses, under the `automations` key, so actions inherit the
 * draft → publish → rollback lifecycle for free and publishing stages every
 * change atomically.
 *
 * The page edits a friendly shape (a flat list of typed parameters); this
 * controller is the translation layer to/from the JSON-Schema shape the
 * runtime (AutomationAction) reads. Nothing here fires a webhook — that only
 * happens at conversation time, behind the SSRF guard and the master flag.
 *
 * Automations are a managed service: Flowstack configures every client's
 * actions, so this authoring surface is gated to the Hermes operator tier.
 * Clients get the read-only Activity view, not this editor.
 */
class AgentActionsController extends Controller
{
    use AuthorizesByTeamRole;
    use AuthorizesHermesOperator;

    private const MAX_ACTIONS = 25;

    private const MAX_PARAMS = 20;

    private const PARAM_TYPES = ['string', 'number', 'boolean'];

    public function index(Request $request): Response
    {
        $this->requireHermesOperator($request);
        $agent = $this->currentAgent($request);

        $draftConfig = null;
        $publishedConfig = null;
        if ($agent !== null) {
            foreach (AgentConfigVersion::query()
                ->where('agent_id', $agent->id)
                ->whereIn('status', [AgentConfigVersion::STATUS_DRAFT, AgentConfigVersion::STATUS_PUBLISHED])
                ->get() as $v) {
                if ($v->status === AgentConfigVersion::STATUS_DRAFT) {
                    $draftConfig = $v->config;
                } else {
                    $publishedConfig = $v->config;
                }
            }
        }

        return Inertia::render('Agents/Actions', [
            // Edit the draft when one exists (null = no draft), else seed from what's live.
            'draftActions' => $draftConfig !== null ? $this->toUi($draftConfig['automations'] ?? []) : null,
            'publishedActions' => $this->toUi($publishedConfig['automations'] ?? []),
            'enabled' => (bool) config('runtime.automation.enabled', false),
            'allowPrivateHosts' => (bool) config('runtime.automation.allow_private_hosts', false),
            'modes' => [AutomationRun::MODE_SYNC, AutomationRun::MODE_ASYNC],
            'paramTypes' => self::PARAM_TYPES,
        ]);
    }

    /**
     * Stage the full automations list into the draft (replacing the
     * draft's `automations` key, preserving its behavior keys). The list is
     * authoritative — the page always sends every action.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->requireHermesOperator($request);
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'edit agent automations');
        $agent = $this->currentAgentOrAbort($request);

        $automations = $this->validateAndNormalize($request, $agent);

        AgentConfigVersion::patchDraft($agent->id, ['automations' => $automations]);

        return back();
    }

    // ── translation + validation ───────────────────────────────────────────────

    /**
     * Validate the incoming UI shape and translate it to the stored
     * (JSON-Schema) shape the runtime reads. Names are normalized to
     * snake_case (mirroring AutomationAction) and duplicates are rejected so
     * the operator sees the collision instead of silently losing an action.
     *
     * Every action carries a stable ULID `id`: the editor round-trips it, so
     * a rename keeps the id and the activity history stays linked. Only ids
     * already minted for this agent are honoured — anything else (new row,
     * forged or duplicated id) gets a fresh ULID.
     *
     * @return list<array<string, mixed>>
     */
    protected function validateAndNormalize(Request $request, Agent $agent): array
    {
        $httpsOnly = ! (bool) config('runtime.automation.allow_private_hosts', false);

        $data = $request->validate([
            'automations' => ['present', 'array', 'max:'.self::MAX_ACTIONS],
            'automations.*.id' => ['nullable', 'string', 'max:26'],
            'automations.*.name' => ['required', 'string', 'max:64'],
            'automations.*.description' => ['nullable', 'string', 'max:500'],
            'automations.*.url' => ['required', 'string', 'max:2000', 'url'],
            'automations.*.mode' => ['required', Rule::in([AutomationRun::MODE_SYNC, AutomationRun::MODE_ASYNC])],
            'automations.*.credit_cost' => ['required', 'integer', 'min:1', 'max:1000'],
            'automations.*.parameters' => ['present', 'array', 'max:'.self::MAX_PARAMS],
            'automations.*.parameters.*.name' => ['required', 'string', 'max:64'],
            'automations.*.parameters.*.type' => ['required', Rule::in(self::PARAM_TYPES)],
            'automations.*.parameters.*.description' => ['nullable', 'string', 'max:200'],
            'automations.*.parameters.*.required' => ['boolean'],
        ]);

        $known = $this->knownActionIds($agent);

        $out = [];
        $seenNames = [];
        $seenIds = [];
        foreach ($data['automations'] as $i => $raw) {
            $id = trim((string) ($raw['id'] ?? ''));
            if ($id === '' || ! isset($known[$id]) || isset($seenIds[$id])) {
                $id = (string) Str::ulid();
            }
            $seenIds[$id] = true;

            $name = $this->normalizeName((string) $raw['name']);
            if ($name === '') {
                throw ValidationException::withMessages([
                    "automations.$i.name" => 'Name must contain at least one letter or number.',
                ]);
            }
            if (isset($seenNames[$name])) {
                throw ValidationException::withMessages([
                    "automations.$i.name" => "Duplicate action name \"$name\" — names must be unique.",
                ]);
            }
            $seenNames[$name] = true;

            $url = trim((string) $raw['url']);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($httpsOnly && $scheme !== 'https') {
                throw ValidationException::withMessages([
                    "automations.$i.url" => 'URL must use https.',
                ]);
            }
            if (! $httpsOnly && ! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages([
                    "automations.$i.url" => 'URL must use http or https.',
                ]);
            }

            $out[] = [
                'id' => $id,
                'name' => $name,
                'description' => trim((string) ($raw['description'] ?? '')),
                'url' => $url,
                'mode' => $raw['mode'],
                'credit_cost' => (int) $raw['credit_cost'],
                'parameters' => $this->buildSchema($raw['parameters'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Flat UI parameter rows → JSON Schema object the runtime/LLM expects.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function buildSchema(array $rows): array
    {
        $properties = [];
        $required = [];
        foreach ($rows as $row) {
            $pname = $this->normalizeName((string) ($row['name'] ?? ''));
            if ($pname === '' || isset($properties[$pname])) {
                continue;
            }
            $prop = ['type' => in_array($row['type'] ?? 'string', self::PARAM_TYPES, true) ? $row['type'] : 'string'];
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc !== '') {
                $prop['description'] = $desc;
            }
            $properties[$pname] = $prop;
            if (! empty($row['required'])) {
                $required[] = $pname;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * Stored automations → flat UI shape for the editor (decomposes the
     * JSON-Schema parameters back into typed rows).
     *
     * @param  mixed  $stored
     * @return list<array<string, mixed>>
     */
    protected function toUi($stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $ui = [];
        foreach ($stored as $a) {
            if (! is_array($a) || ! isset($a['name'], $a['url'])) {
                continue;
            }
            $props = is_array($a['parameters']['properties'] ?? null) ? $a['parameters']['properties'] : [];
            $req = is_array($a['parameters']['required'] ?? null) ? $a['parameters']['required'] : [];

            $rows = [];
            foreach ($props as $pname => $schema) {
                $schema = is_array($schema) ? $schema : [];
                $rows[] = [
                    'name' => (string) $pname,
                    'type' => in_array($schema['type'] ?? 'string', self::PARAM_TYPES, true) ? $schema['type'] : 'string',
                    'description' => (string) ($schema['description'] ?? ''),
                    'required' => in_array($pname, $req, true),
                ];
            }

            $ui[] = [
                'id' => trim((string) ($a['id'] ?? '')),
                'name' => (string) $a['name'],
                'description' => (string) ($a['description'] ?? ''),
                'url' => (string) $a['url'],
                'mode' => ($a['mode'] ?? AutomationRun::MODE_SYNC) === AutomationRun::MODE_ASYNC
                    ? AutomationRun::MODE_ASYNC
                    : AutomationRun::MODE_SYNC,
                'credit_cost' => max(1, (int) ($a['credit_cost'] ?? 1)),
                'parameters' => $rows,
            ];
        }

        return $ui;
    }

    /**
     * Ids already minted for this agent's actions (draft + published) — the
     * only ids a save may claim, so history can't be grafted onto an
     * unrelated action.
     *
     * @return array<string, true>
     */
    private function knownActionIds(Agent $agent): array
    {
        $known = [];
        foreach (AgentConfigVersion::query()
            ->where('agent_id', $agent->id)
            ->whereIn('status', [AgentConfigVersion::STATUS_DRAFT, AgentConfigVersion::STATUS_PUBLISHED])
            ->get() as $v) {
            $automations = $v->config['automations'] ?? [];
            foreach (is_array($automations) ? $automations : [] as $a) {
                $id = is_array($a) ? trim((string) ($a['id'] ?? '')) : '';
                if ($id !== '') {
                    $known[$id] = true;
                }
            }
        }

        return $known;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? '';

        return trim($name, '_');
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
