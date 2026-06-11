<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Agent;
use App\Models\Team;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\Models\KbDocument;
use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Smalot\PdfParser\Parser as PdfParser;

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
    use AuthorizesByTeamRole;

    /** Voiceflow's documented per-file upload ceiling. */
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** @var array<int, string> */
    private const ACCEPTED_DOCUMENT_TYPES = ['url', 'pdf', 'text', 'docx', 'md', 'csv', 'xlsx', 'table'];

    public function __construct(
        protected VoiceflowService $voiceflow,
        protected KnowledgeStore $knowledge,
    ) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $agent = $team?->currentAgent;

        if ($native = $this->nativeAgent($request)) {
            return $this->nativeIndex($request, $native);
        }

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
        $this->requireCapability($request, fn (Role $r) => $r->canAddKnowledge(), 'add knowledge documents');

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeStoreUrl($agent, $data['url'], $data['name'] ?? null);
        }

        $this->abortIfUnconfigured();

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
        $this->requireCapability($request, fn (Role $r) => $r->canAddKnowledge(), 'upload knowledge files');

        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeStoreFile($request, $agent);
        }

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
        $this->requireCapability($request, fn (Role $r) => $r->canAddKnowledge(), 'paste knowledge text');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:200000'],
        ]);

        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeStoreText($agent, $data['name'], $data['text']);
        }

        $this->abortIfUnconfigured();

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
    public function show(Request $request, string $documentID): JsonResponse
    {
        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeShow($agent, $documentID);
        }

        $this->abortIfUnconfigured();

        try {
            $doc = $this->voiceflow->getKbDocument($documentID);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not load that document.'], 502);
        }

        return response()->json($doc);
    }

    public function destroy(Request $request, string $documentID): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canDeleteKnowledge(), 'delete knowledge documents');

        if ($agent = $this->nativeAgent($request)) {
            $this->knowledge->deleteDocument($agent->id, (int) $documentID);

            return back();
        }

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
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        if ($agent = $this->nativeAgent($request)) {
            return $this->nativeQuery($agent, $data['question']);
        }

        $this->abortIfUnconfigured();

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

    // ── Native runtime branch ───────────────────────────────────────────────
    //
    // Same page, same JSON shapes, different store: documents live in our
    // kb_documents/kb_chunks tables and retrieval is the runtime's RAG
    // pipeline. Mapping note: the Vue page renders Voiceflow's document
    // shape ({documentID, data:{name,type,url}, status:{type}, updatedAt}),
    // so the native list serializes into exactly that shape — zero frontend
    // changes.

    protected function nativeAgent(Request $request): ?Agent
    {
        $team = $request->user()?->currentTeam;
        if (! $team instanceof Team) {
            return null;
        }

        $agent = $team->currentAgent;

        return ($agent instanceof Agent && $agent->getAttribute('runtime_mode') === Agent::RUNTIME_NATIVE)
            ? $agent
            : null;
    }

    protected function nativeIndex(Request $request, Agent $agent): Response
    {
        $configured = (string) config('runtime.embeddings.openai_api_key') !== '';

        $nativeTypes = ['url', 'pdf', 'text', 'md', 'csv'];
        $filter = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $nativeTypes)],
        ])['type'] ?? null;

        $documents = [];
        if ($configured) {
            foreach ($this->knowledge->listDocuments($agent->id) as $doc) {
                $type = (string) ($doc['metadata']['source'] ?? 'text');
                if ($filter !== null && $type !== $filter) {
                    continue;
                }
                $documents[] = [
                    'documentID' => (string) $doc['id'],
                    'data' => [
                        'name' => $doc['title'],
                        'type' => $type,
                        'url' => $doc['metadata']['source_url'] ?? null,
                    ],
                    'status' => ['type' => 'SUCCESS'],
                    'updatedAt' => $doc['created_at'],
                ];
            }
        }

        return Inertia::render('Knowledge/Index', [
            'configured' => $configured,
            'documents' => $documents,
            'total' => count($documents),
            'error' => $configured ? null : 'Set OPENAI_API_KEY to enable this agent\'s knowledge base.',
            'filter' => ['type' => $filter],
            'accepted_types' => $nativeTypes,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
            ],
        ]);
    }

    protected function nativeStoreUrl(Agent $agent, string $url, ?string $name): RedirectResponse
    {
        try {
            $response = Http::timeout(20)->withHeaders(['User-Agent' => 'FlowstackBot/1.0'])->get($url);
            if ($response->failed()) {
                return back()->withErrors(['url' => 'That URL returned HTTP '.$response->status().'.']);
            }

            $content = $this->htmlToText($response->body());
            if (mb_strlen($content) < 40) {
                return back()->withErrors(['url' => 'That page had no readable text content.']);
            }

            $this->knowledge->ingestDocument($agent->id, $name ?: $url, $content, [
                'source' => 'url',
                'source_url' => $url,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['url' => 'Could not fetch or index that URL. Check it and try again.']);
        }

        return back();
    }

    protected function nativeStoreFile(Request $request, Agent $agent): RedirectResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) (self::MAX_UPLOAD_BYTES / 1024),
                'mimetypes:application/pdf,text/plain,text/markdown,text/csv',
            ],
        ]);

        $file = $data['file'];
        $originalName = $file->getClientOriginalName();
        $mime = (string) $file->getMimeType();

        try {
            $content = $mime === 'application/pdf'
                ? (new PdfParser)->parseFile($file->getRealPath())->getText()
                : (string) file_get_contents($file->getRealPath());

            if (mb_strlen(trim($content)) < 10) {
                return back()->withErrors(['file' => 'No readable text found in that file (scanned/image PDFs are not supported yet).']);
            }

            $this->knowledge->ingestDocument($agent->id, $originalName, $content, [
                'source' => str_ends_with($originalName, '.pdf') ? 'pdf' : 'text',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'Could not index that file. Supported on this agent: PDF, TXT, MD, CSV under 10 MB.']);
        }

        return back();
    }

    protected function nativeStoreText(Agent $agent, string $name, string $text): RedirectResponse
    {
        try {
            $this->knowledge->ingestDocument($agent->id, $name, $text, ['source' => 'text']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['text' => 'Could not index that text. Check OPENAI_API_KEY and try again.']);
        }

        return back();
    }

    protected function nativeShow(Agent $agent, string $documentID): JsonResponse
    {
        $doc = KbDocument::query()
            ->where('agent_id', $agent->id)
            ->find((int) $documentID);

        if ($doc === null) {
            return response()->json(['error' => 'Could not load that document.'], 404);
        }

        return response()->json([
            'data' => [
                'name' => $doc->title,
                'type' => (string) ($doc->metadata['source'] ?? 'text'),
                'url' => $doc->metadata['source_url'] ?? null,
                'updatedAt' => $doc->updated_at->toIso8601String(),
            ],
            'chunks' => $doc->chunks()->orderBy('position')->pluck('content')->map(fn ($c) => ['content' => $c])->all(),
            'metadata' => (array) ($doc->metadata ?? []),
        ]);
    }

    protected function nativeQuery(Agent $agent, string $question): JsonResponse
    {
        try {
            $chunks = $this->knowledge->search($agent->id, $question, 5);

            $answer = null;
            if ($chunks !== []) {
                $context = implode("\n\n", array_map(fn (array $c) => '('.$c['document_title'].') '.$c['chunk'], $chunks));
                $result = app(AnthropicClient::class)->complete(
                    system: 'Answer the question using ONLY the provided context. If the context does not contain the answer, say so plainly. Be concise.',
                    messages: [['role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$question}"]],
                );
                $answer = $result->text;
            }

            return response()->json([
                'answer' => $answer,
                'chunks' => array_map(fn (array $c) => [
                    'content' => $c['chunk'],
                    'source' => ['name' => $c['document_title']],
                    'score' => $c['score'],
                ], $chunks),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Knowledge base query failed.'], 502);
        }
    }

    /**
     * Crude-but-effective HTML → text: drop script/style blocks, strip
     * tags, collapse whitespace. A readability-grade extractor can slot
     * in later; for marketing/docs pages this captures the substance.
     */
    protected function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
