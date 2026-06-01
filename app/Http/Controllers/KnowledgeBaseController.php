<?php

namespace App\Http\Controllers;

use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage the Voiceflow Knowledge Base: list/add documents and run KB queries.
 * The agent answers leads grounded in these documents.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(protected VoiceflowService $voiceflow) {}

    public function index(): Response
    {
        $configured = $this->voiceflow->isConfigured();

        $documents = [];
        $error = null;
        if ($configured) {
            try {
                $documents = $this->voiceflow->listKbDocuments()['data'];
            } catch (\Throwable $e) {
                report($e);
                $error = 'Could not load knowledge base documents.';
            }
        }

        return Inertia::render('Knowledge/Index', [
            'configured' => $configured,
            'documents' => $documents,
            'error' => $error,
        ]);
    }

    public function storeUrl(Request $request): RedirectResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->voiceflow->createKbUrlDocument($data['url'], $data['name'] ?? null);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['url' => 'Voiceflow rejected the document. Check the URL and try again.']);
        }

        return back();
    }

    public function query(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->voiceflow->queryKnowledgeBase($data['question']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Knowledge base query failed.'], 502);
        }

        return response()->json($result);
    }

    protected function abortIfUnconfigured(): void
    {
        abort_unless($this->voiceflow->isConfigured(), 503, 'Voiceflow is not configured.');
    }
}
