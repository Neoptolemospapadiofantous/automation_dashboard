<?php

namespace App\Http\Controllers\Voiceflow;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Team;
use App\Services\Voiceflow\Client\AnalyticsClient;
use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvaluationsController extends Controller
{
    public function __construct(
        protected VoiceflowService $voiceflow,
        protected AnalyticsClient $analytics,
    ) {}

    public function index(): Response
    {
        if (! $this->voiceflow->isConfigured()) {
            return Inertia::render('Agents/Evaluations', [
                'configured' => false,
                'evaluations' => [],
            ]);
        }

        try {
            $evaluations = $this->analytics->listEvaluations();
        } catch (\Throwable $e) {
            report($e);
            $evaluations = [];
        }

        return Inertia::render('Agents/Evaluations', [
            'configured' => true,
            'evaluations' => $evaluations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:boolean,number,string,option'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->analytics->createEvaluation([
                'name' => $data['name'],
                'type' => $data['type'],
                'description' => $data['description'] ?? '',
                // Per-agent project, NOT the global env fallback — using config here
                // would stamp evaluations onto the platform's project for
                // every tenant (cross-tenant wrongness).
                'projectID' => (string) $this->currentAgentProjectId($request),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['name' => 'Voiceflow rejected the evaluation. Check the type + name.']);
        }

        return back();
    }

    public function show(string $evaluationId): JsonResponse
    {
        $this->abortIfUnconfigured();

        try {
            $detail = $this->analytics->getEvaluation($evaluationId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Failed to fetch evaluation'], 502);
        }

        if ($detail === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($detail);
    }

    public function run(Request $request, string $evaluationId): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'transcript_id' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $this->analytics->runEvaluation($evaluationId, $data['transcript_id']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Evaluation run failed'], 502);
        }

        return response()->json($result);
    }

    public function destroy(string $evaluationId): RedirectResponse
    {
        $this->abortIfUnconfigured();

        try {
            $this->analytics->deleteEvaluation($evaluationId);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['evaluations' => 'Voiceflow rejected the delete.']);
        }

        return back();
    }

    protected function abortIfUnconfigured(): void
    {
        abort_unless($this->voiceflow->isConfigured(), 503, 'Voiceflow is not configured.');
    }

    /**
     * The CURRENT AGENT's Voiceflow project id — never the global env
     * fallback (that would stamp evaluations onto the platform's project
     * for every tenant).
     */
    protected function currentAgentProjectId(Request $request): string
    {
        $team = $request->user()?->currentTeam;
        if (! $team instanceof Team) {
            return '';
        }
        $agent = $team->currentAgent;

        return $agent instanceof Agent ? (string) $agent->voiceflow_project_id : '';
    }
}
