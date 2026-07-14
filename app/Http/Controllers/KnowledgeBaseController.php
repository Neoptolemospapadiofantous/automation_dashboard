<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\Models\KbDocument;
use App\Support\PublicWebPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Per-agent Knowledge Base management on the native runtime's RAG store.
 *
 * Documents are scoped to the team's current agent — switching agents in
 * the picker shows a different document set. Content is chunked + embedded
 * at upload (KnowledgeBase) and retrieved by the engine at chat time.
 *
 *  - GET    /knowledge              → list + type filter
 *  - POST   /knowledge/url          → fetch a page, strip to text, ingest
 *  - POST   /knowledge/file         → PDF/TXT/MD/CSV upload (10MB cap)
 *  - POST   /knowledge/text         → paste raw text
 *  - GET    /knowledge/{documentID} → chunks + metadata (side panel)
 *  - DELETE /knowledge/{documentID} → remove (chunks cascade)
 *  - POST   /knowledge/query        → RAG search + LLM-synthesized answer
 */
class KnowledgeBaseController extends Controller
{
    use AuthorizesByTeamRole;

    /** Per-file upload ceiling. */
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** @var array<int, string> */
    private const ACCEPTED_TYPES = ['url', 'pdf', 'text', 'md', 'csv'];

    public function __construct(protected KnowledgeStore $knowledge) {}

    public function index(Request $request): Response
    {
        $agent = $this->currentAgent($request);
        $configured = (string) config('runtime.embeddings.openai_api_key') !== '';

        $filter = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ACCEPTED_TYPES)],
        ])['type'] ?? null;

        $documents = [];
        if ($configured && $agent !== null) {
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
            'configured' => $configured && $agent !== null,
            'documents' => $documents,
            'total' => count($documents),
            'error' => $configured ? null : 'Set OPENAI_API_KEY to enable this agent\'s knowledge base.',
            'filter' => ['type' => $filter],
            'accepted_types' => self::ACCEPTED_TYPES,
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
        $agent = $this->currentAgentOrAbort($request);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        // Fetch is SSRF-guarded (PublicWebPage rejects private/loopback/
        // metadata destinations and re-validates every redirect hop) — this
        // endpoint is authenticated, but a tenant could otherwise aim it at
        // 169.254.169.254 or the private network and read the response back.
        try {
            $content = app(PublicWebPage::class)->fetchText($data['url']);

            $this->knowledge->ingestDocument($agent->id, $data['name'] ?: $data['url'], $content, [
                'source' => 'url',
                'source_url' => $data['url'],
            ]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['url' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['url' => 'Could not fetch or index that URL. Check it and try again.']);
        }

        return back();
    }

    public function storeFile(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canAddKnowledge(), 'upload knowledge files');
        $agent = $this->currentAgentOrAbort($request);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) (self::MAX_UPLOAD_BYTES / 1024), // Laravel max is in kilobytes
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

            return back()->withErrors(['file' => 'Could not index that file. Supported: PDF, TXT, MD, CSV under 10 MB.']);
        }

        return back();
    }

    public function storeText(Request $request): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canAddKnowledge(), 'paste knowledge text');
        $agent = $this->currentAgentOrAbort($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:200000'],
        ]);

        try {
            $this->knowledge->ingestDocument($agent->id, $data['name'], $data['text'], ['source' => 'text']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['text' => 'Could not index that text. Check OPENAI_API_KEY and try again.']);
        }

        return back();
    }

    /**
     * Inspect one document — chunks + metadata for the side panel.
     */
    public function show(Request $request, string $documentID): JsonResponse
    {
        $agent = $this->currentAgentOrAbort($request);

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

    public function destroy(Request $request, string $documentID): RedirectResponse
    {
        $this->requireCapability($request, fn (Role $r) => $r->canDeleteKnowledge(), 'delete knowledge documents');
        $agent = $this->currentAgentOrAbort($request);

        $this->knowledge->deleteDocument($agent->id, (int) $documentID);

        return back();
    }

    /**
     * Ask the KB a question: RAG search + LLM synthesis grounded ONLY in
     * the retrieved chunks.
     */
    public function query(Request $request): JsonResponse
    {
        $agent = $this->currentAgentOrAbort($request);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        // This endpoint runs a real LLM synthesis — it bills like a chat
        // turn (tier multiplier). Pricing-audit finding: unbilled, it was
        // a free-LLM vector that kept working at zero balance.
        $team = $agent->team;
        try {
            if ($team instanceof Team) {
                app(CreditMeter::class)->consume(
                    team: $team,
                    amount: AgentConfigVersion::creditsPerMessage($agent->id),
                    agentId: $agent->id,
                    meta: ['kb_query' => true],
                );
            }
        } catch (OutOfCredits) {
            return response()->json(['error' => 'Out of credits for this billing period.'], 402);
        }

        try {
            $chunks = $this->knowledge->search($agent->id, $data['question'], 5);

            $answer = null;
            if ($chunks !== []) {
                $context = implode("\n\n", array_map(fn (array $c) => '('.$c['document_title'].') '.$c['chunk'], $chunks));
                $result = app(AnthropicClient::class)->complete(
                    system: 'Answer the question using ONLY the provided context. If the context does not contain the answer, say so plainly. Be concise.',
                    messages: [['role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$data['question']}"]],
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

    // ── helpers ──────────────────────────────────────────────────────────────

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
