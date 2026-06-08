<?php

namespace App\Http\Controllers;

use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-agent Knowledge Base management.
 *
 * Documents are scoped to the team's current agent automatically — the
 * VoiceflowService injected here is the scoped, agent-bound instance
 * (see AppServiceProvider). Switching agents in the picker swaps the
 * underlying Voiceflow project; the same listKbDocuments() call then
 * returns a different document set.
 *
 * Surfaces the Voiceflow Knowledge Base public API:
 *  - GET /knowledge → list + type filter
 *  - POST /knowledge/url → create URL document (scrape)
 *  - POST /knowledge/file → create file document (PDF/TXT/DOCX/CSV/XLSX, 10MB cap)
 *  - GET /knowledge/{documentID} → fetch one doc with chunks + metadata
 *  - DELETE /knowledge/{documentID} → remove
 *  - POST /knowledge/query → ask the KB
 */
class KnowledgeBaseController extends Controller
{
    /** Voiceflow's documented per-file upload ceiling. */
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** @var array<int, string> */
    private const ACCEPTED_DOCUMENT_TYPES = ['url', 'pdf', 'text', 'docx', 'md', 'csv', 'xlsx', 'table'];

    public function __construct(protected VoiceflowService $voiceflow) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $agent = $team?->currentAgent;
        $configured = $this->voiceflow->isConfigured();

        $filter = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ACCEPTED_DOCUMENT_TYPES)],
        ])['type'] ?? null;

        $documents = [];
        $total = 0;
        $error = null;

        if ($configured) {
            try {
                $page = $this->voiceflow->listKbDocuments(limit: 50, documentType: $filter);
                $documents = $page['data'];
                $total = $page['total'];
            } catch (\Throwable $e) {
                report($e);
                $error = 'Could not load this agent\'s documents.';
            }
        }

        return Inertia::render('Knowledge/Index', [
            'configured' => $configured,
            'documents' => $documents,
            'total' => $total,
            'error' => $error,
            'filter' => ['type' => $filter],
            'accepted_types' => self::ACCEPTED_DOCUMENT_TYPES,
            // Surface the current agent so the UI can scope its language
            // ("This agent's documents") and the user understands docs
            // follow the agent picker, not the team.
            'agent' => $agent ? [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
            ] : null,
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

    /**
     * Upload a file (PDF/DOCX/TXT/MD/CSV/XLSX) into the current agent's KB.
     * Voiceflow caps at 10 MB; Laravel rejects larger uploads at the
     * validation step before we open the file handle.
     */
    public function storeFile(Request $request): RedirectResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) (self::MAX_UPLOAD_BYTES / 1024), // Laravel max is in kilobytes
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel',
            ],
        ]);

        $file = $data['file'];
        $originalName = $file->getClientOriginalName();

        try {
            $this->voiceflow->createKbFileDocument(
                filePath: $file->getRealPath(),
                name: $originalName,
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'Voiceflow rejected the upload. Check the file format (PDF, DOCX, TXT, MD, CSV, XLSX) and that it is under 10 MB.']);
        }

        return back();
    }

    /**
     * Paste raw text directly as a KB document (no file upload).
     * Useful for short policy snippets, FAQ entries, etc.
     */
    public function storeText(Request $request): RedirectResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:200000'],
        ]);

        try {
            $this->voiceflow->createKbTextDocument($data['text'], $data['name']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['text' => 'Voiceflow rejected the text. Try shorter content.']);
        }

        return back();
    }

    /**
     * Inspect one document — chunks + metadata. Returned as JSON because
     * the UI renders it in a side panel without a full page navigation.
     */
    public function show(string $documentID): JsonResponse
    {
        $this->abortIfUnconfigured();

        try {
            $doc = $this->voiceflow->getKbDocument($documentID);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not load that document.'], 502);
        }

        return response()->json($doc);
    }

    public function destroy(string $documentID): RedirectResponse
    {
        $this->abortIfUnconfigured();

        try {
            $this->voiceflow->deleteKbDocument($documentID);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['delete' => 'Could not delete that document. It may have already been removed.']);
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
