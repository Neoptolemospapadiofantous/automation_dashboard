<?php

namespace App\Http\Controllers\Voiceflow;

use App\Http\Controllers\Controller;
use App\Services\Voiceflow\Client\RealtimeClient;
use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnvironmentsController extends Controller
{
    public function __construct(
        protected VoiceflowService $voiceflow,
        protected RealtimeClient $realtime,
    ) {}

    public function index(Request $request): Response
    {
        $projectId = (string) ($request->user()->currentTeam->currentAgent?->voiceflow_project_id
            ?? config('services.voiceflow.project_id', ''));

        if (! $this->voiceflow->isConfigured() || $projectId === '') {
            return Inertia::render('Agents/Environments', [
                'configured' => false,
                'environments' => [],
                'trafficSplit' => null,
                'projectId' => null,
            ]);
        }

        $environments = [];
        $trafficSplit = null;

        try {
            $environments = $this->realtime->listEnvironments($projectId);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $trafficSplit = $this->realtime->getTrafficSplit($projectId);
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('Agents/Environments', [
            'configured' => true,
            'projectId' => $projectId,
            'environments' => array_values(array_filter(array_map(
                fn (array $env) => $this->normalizeEnvironment($env),
                $environments,
            ))),
            'trafficSplit' => $trafficSplit,
        ]);
    }

    /**
     * Normalize Voiceflow's raw environment payload into the shape the Vue
     * page renders. Voiceflow returns Mongo-style `_id` (and sometimes
     * `name` instead of `alias`) — passing the raw body through left
     * env.alias AND env.id undefined in the page, which crashed the render
     * with "Ziggy error: 'alias' parameter is required" the moment
     * exportUrl() built a route from undefined.
     *
     * Returns null for rows with no usable reference at all (skipped).
     *
     * @param  array<string, mixed>  $env
     * @return array<string, mixed>|null
     */
    protected function normalizeEnvironment(array $env): ?array
    {
        $id = (string) ($env['id'] ?? $env['_id'] ?? '');
        $alias = (string) ($env['alias'] ?? $env['name'] ?? '');

        if ($id === '' && $alias === '') {
            return null; // nothing to address it by — can't render actions
        }

        return [
            'id' => $id !== '' ? $id : $alias,
            'alias' => $alias !== '' ? $alias : null,
            'isLive' => (bool) ($env['isLive'] ?? $env['live'] ?? false),
            'updatedAt' => $env['updatedAt'] ?? $env['updated_at'] ?? null,
        ];
    }

    public function clone(Request $request): RedirectResponse
    {
        $this->abortIfUnconfigured();
        $projectId = (string) $this->currentProjectId($request);

        $data = $request->validate([
            'source_environment_id' => ['required', 'string', 'max:120'],
            'alias' => ['required', 'string', 'max:60'],
        ]);

        try {
            $this->realtime->cloneEnvironment($projectId, $data['source_environment_id'], $data['alias']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['alias' => 'Voiceflow rejected the clone — check the alias is unique.']);
        }

        return back();
    }

    public function publish(Request $request, string $idOrAlias): RedirectResponse
    {
        $this->abortIfUnconfigured();
        $projectId = (string) $this->currentProjectId($request);

        try {
            $this->realtime->publishEnvironment($projectId, $idOrAlias);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['environment' => 'Publish failed — see logs.']);
        }

        return back();
    }

    public function destroy(Request $request, string $environmentId): RedirectResponse
    {
        $this->abortIfUnconfigured();
        $projectId = (string) $this->currentProjectId($request);

        try {
            $this->realtime->deleteEnvironment($projectId, $environmentId);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['environment' => 'Delete failed — see logs.']);
        }

        return back();
    }

    public function export(Request $request, string $alias): StreamedResponse
    {
        $this->abortIfUnconfigured();
        $projectId = (string) $this->currentProjectId($request);
        $version = $request->query('version', 'published');
        if (! in_array($version, ['draft', 'published'], true)) {
            abort(422, 'version must be draft or published');
        }

        try {
            $payload = $this->realtime->exportEnvironmentJson($projectId, $alias, $version);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Export failed.');
        }

        $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
        $filename = sprintf('voiceflow-%s-%s-%s.json', $projectId, $alias, $version);

        return new StreamedResponse(function () use ($body): void {
            echo $body;
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();
        $projectId = (string) $this->currentProjectId($request);

        try {
            $split = $this->realtime->getTrafficSplit($projectId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Failed to fetch traffic split'], 502);
        }

        return response()->json($split);
    }

    protected function abortIfUnconfigured(): void
    {
        abort_unless($this->voiceflow->isConfigured(), 503, 'Voiceflow is not configured.');
    }

    protected function currentProjectId(Request $request): string
    {
        return (string) ($request->user()->currentTeam->currentAgent?->voiceflow_project_id
            ?? config('services.voiceflow.project_id', ''));
    }
}
