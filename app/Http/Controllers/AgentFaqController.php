<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Http\Controllers\Concerns\AuthorizesHermesOperator;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator "FAQ" editor — author the deterministic canned answers an agent
 * serves without calling the LLM. A visitor who taps a category chip or types
 * a keyword-matched question gets the stored answer for zero tokens and zero
 * credits (see CannedAnswers), which is most of a landing page's traffic.
 *
 * Writes into the SAME draft config the behavior + Actions editors use, under
 * the `canned_answers` key, so it inherits the draft → publish → rollback
 * lifecycle for free and publishing stages every change atomically.
 *
 * Gated to the Hermes operator tier like Actions: canned answers are part of
 * the managed-service setup, not a client-facing knob.
 */
class AgentFaqController extends Controller
{
    use AuthorizesByTeamRole;
    use AuthorizesHermesOperator;

    private const MAX_ANSWERS = 30;

    private const MAX_KEYWORDS = 20;

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

        return Inertia::render('Agents/Faq', [
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name, 'slug' => $agent->slug] : null,
            'draftAnswers' => $draftConfig !== null ? $this->toUi($draftConfig['canned_answers'] ?? []) : null,
            'publishedAnswers' => $this->toUi($publishedConfig['canned_answers'] ?? []),
            'hasDraft' => $draftConfig !== null,
        ]);
    }

    /**
     * Stage the full canned-answers list into the draft (replacing the
     * draft's `canned_answers` key, preserving its other keys). The list is
     * authoritative — the page always sends every row.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->requireHermesOperator($request);
        $this->requireCapability($request, fn (Role $r) => $r->canUpdateAgent(), 'edit agent FAQ');
        $agent = $this->currentAgentOrAbort($request);

        $answers = $this->validateAndNormalize($request);

        AgentConfigVersion::patchDraft($agent->id, ['canned_answers' => $answers]);

        return back();
    }

    /**
     * Validate the incoming UI shape and translate it to the stored shape the
     * runtime reads. Keywords arrive as a comma-separated string and are split
     * to a normalized list; duplicate categories are rejected so the operator
     * sees the collision instead of silently losing a chip.
     *
     * @return list<array<string, mixed>>
     */
    protected function validateAndNormalize(Request $request): array
    {
        $data = $request->validate([
            'answers' => ['present', 'array', 'max:'.self::MAX_ANSWERS],
            'answers.*.category' => ['required', 'string', 'max:64'],
            'answers.*.keywords' => ['nullable', 'string', 'max:500'],
            'answers.*.answer' => ['required', 'string', 'max:2000'],
        ]);

        $out = [];
        $seen = [];
        foreach ($data['answers'] as $i => $raw) {
            $category = trim((string) $raw['category']);
            $key = mb_strtolower($category);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "answers.$i.category" => "Duplicate category \"$category\" — categories must be unique.",
                ]);
            }
            $seen[$key] = true;

            $out[] = [
                'category' => $category,
                'keywords' => $this->splitKeywords((string) ($raw['keywords'] ?? '')),
                'answer' => trim((string) $raw['answer']),
            ];
        }

        return $out;
    }

    /**
     * "cost, how much, price" → ['cost', 'how much', 'price'] — lowercased,
     * trimmed, de-duped, capped.
     *
     * @return list<string>
     */
    protected function splitKeywords(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $kw) {
            $kw = mb_strtolower(trim($kw));
            if ($kw !== '' && ! in_array($kw, $out, true)) {
                $out[] = $kw;
            }
            if (count($out) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Stored answers → flat UI shape (keywords array recombined to a string
     * for the comma-separated text field).
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
        foreach ($stored as $row) {
            if (! is_array($row) || ! isset($row['category'], $row['answer'])) {
                continue;
            }
            $keywords = is_array($row['keywords'] ?? null) ? $row['keywords'] : [];
            $ui[] = [
                'category' => (string) $row['category'],
                'keywords' => implode(', ', array_map('strval', $keywords)),
                'answer' => (string) $row['answer'],
            ];
        }

        return $ui;
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
