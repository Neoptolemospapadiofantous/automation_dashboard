<?php

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Models\ModuleInterest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Suite — every module in one place, in two kinds of entry that are
 * kept apart on purpose: the app (self-serve, this dashboard, billed by
 * plan) and the four services built to order (quoted after a free call,
 * invoiced separately, the dashboard only points at them). Catalogue and
 * framing rules live in config/suite.php.
 *
 * A `coming` module is not sold here and is never described as working;
 * the one action it offers is "request it", which records interest per
 * team so the next build is decided on counts rather than guesses.
 */
class SuiteController extends Controller
{
    /**
     * Plan order for `min_plan` gating. Kept here rather than on the Plan
     * enum so the billing domain (which carries margin invariants) is not
     * touched for a display concern.
     *
     * @var array<string, int>
     */
    private const PLAN_RANK = [
        'free' => 0,
        'starter' => 1,
        'pro' => 2,
        'business' => 3,
    ];

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 404);

        $plan = $team->planObject();
        $requested = ModuleInterest::query()
            ->where('team_id', $team->id)
            ->pluck('module_key')
            ->all();

        $modules = [];
        /** @var array<int, array<string, mixed>> $catalogue */
        $catalogue = config('suite.modules', []);
        foreach ($catalogue as $m) {
            $minPlan = is_string($m['min_plan'] ?? null) ? $m['min_plan'] : null;
            $modules[] = [
                'key' => $m['key'],
                'line' => $m['line'],
                'status' => $m['status'],
                'name' => $m['name'],
                'blurb' => $m['blurb'],
                'href' => is_string($m['route'] ?? null) ? route($m['route']) : null,
                // Built-to-order services link out to the page that describes them.
                'url' => is_string($m['url'] ?? null) ? $m['url'] : null,
                'min_plan' => $minPlan,
                'min_plan_label' => $minPlan !== null ? Plan::from($minPlan)->label() : null,
                'on_plan' => $minPlan === null || $this->planCovers($plan, $minPlan),
                'requested' => in_array($m['key'], $requested, true),
            ];
        }

        return Inertia::render('Suite/Index', [
            'modules' => $modules,
            'plan' => ['key' => $plan->value, 'label' => $plan->label()],
            'audit_url' => (string) config('suite.audit_url'),
        ]);
    }

    /**
     * Record that this team wants a module that is not built yet. Only
     * `coming` modules accept a request — a live module is opened, a
     * built-to-order service is a conversation, and neither is a vote.
     */
    public function request(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 404);

        $data = $request->validate([
            'module' => ['required', 'string', 'max:40'],
        ]);

        /** @var array<int, array<string, mixed>> $catalogue */
        $catalogue = config('suite.modules', []);
        $module = collect($catalogue)
            ->first(fn (array $m) => $m['key'] === $data['module']);

        if (! is_array($module) || ($module['status'] ?? null) !== 'coming') {
            throw ValidationException::withMessages([
                'module' => 'That module is not one you can request.',
            ]);
        }

        ModuleInterest::query()->firstOrCreate(
            ['team_id' => $team->id, 'module_key' => $module['key']],
            ['user_id' => $request->user()->id],
        );

        return back()->with('flash', [
            'bannerStyle' => 'success',
            'banner' => "Noted — we'll tell you when {$module['name']} is ready.",
        ]);
    }

    private function planCovers(Plan $plan, string $minPlan): bool
    {
        // The left side is deliberately unguarded: PLAN_RANK's keys are the Plan enum's
        // cases, and phpstan proves it — add a case to the enum without adding it here
        // and the analysis fails at the gate, which is a better guard than `?? 0`
        // silently ranking a new plan as Free. (A `?? 0` on this side is itself a
        // phpstan error: the offset always exists.)
        return self::PLAN_RANK[$plan->value] >= (self::PLAN_RANK[$minPlan] ?? 0);
    }
}
