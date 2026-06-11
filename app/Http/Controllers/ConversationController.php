<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * List the current team's conversations, most recent first.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        // Optional cross-link filter: LeadCard "View conversations" chip
        // links here with ?lead_id=N. Validate the lead belongs to the
        // current team + agent (no cross-tenant peek via URL guessing).
        $leadFilter = null;
        if ($request->filled('lead_id')) {
            $leadFilter = Lead::query()
                ->where('team_id', $team->id)
                ->forAgent($team->current_agent_id)
                ->find($request->integer('lead_id'));
            abort_if($leadFilter === null, 404, 'Lead not found in this team.');
        }

        // Phase G: agent-scoped so switching agents swaps the conversation
        // list. forAgent(null) returns no rows (no current agent = nothing to show).
        $conversations = Conversation::query()
            ->where('team_id', $team->id)
            ->forAgent($team->current_agent_id)
            ->when($leadFilter, fn ($q) => $q->where('lead_id', $leadFilter->id))
            ->with('lead:id,name,email')
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Conversations/Index', [
            'conversations' => $conversations,
            'filter_lead' => $leadFilter ? [
                'id' => $leadFilter->id,
                'name' => $leadFilter->name,
                'email' => $leadFilter->email,
            ] : null,
        ]);
    }

    /**
     * Show a single conversation with its full transcript.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->team_id === $team->id, 403);

        // Phase G: detail view is reachable only when the conversation
        // belongs to the team's CURRENT agent. Prevents users from
        // seeing other agents' transcripts via URL guessing after
        // switching agents.
        abort_unless(
            $conversation->agent_id === $team->current_agent_id,
            404,
        );

        $conversation->load('lead:id,name,email');
        $messages = $conversation->messages()->orderBy('sequence')->get();

        return Inertia::render('Conversations/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Force-end a conversation (marks it ended locally; for native-runtime
     * sessions the engine's flow_state is the source of truth and the next
     * launch resets it anyway).
     */
    public function endUpstream(
        Request $request,
        Conversation $conversation,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->team_id === $team->id, 403);
        abort_unless($conversation->agent_id === $team->current_agent_id, 404);

        $conversation->forceFill(['ended_at' => now(), 'status' => 'ended'])->save();

        return back();
    }

    /**
     * GDPR-grade delete: drop the conversation + its messages. Irreversible.
     */
    public function deleteUpstream(
        Request $request,
        Conversation $conversation,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->team_id === $team->id, 403);
        abort_unless($conversation->agent_id === $team->current_agent_id, 404);

        $conversation->messages()->delete();
        $conversation->delete();

        return redirect()->route('conversations.index');
    }

    /**
     * Search messages across the team's conversations.
     *
     * Uses Scout (Typesense hybrid keyword + semantic search) when configured;
     * falls back to a MySQL LIKE/fulltext scan otherwise so it works everywhere.
     */
    public function search(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $query = trim((string) $request->input('q', ''));

        $results = collect();

        if ($query !== '') {
            $results = $this->runSearch($team->id, $team->current_agent_id, $query);
        }

        return Inertia::render('Conversations/Search', [
            'q' => $query,
            'results' => $results,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function runSearch(int $teamId, ?int $agentId, string $query): Collection
    {
        // Use the Scout engine only when a real one is configured (e.g.
        // typesense). Otherwise do a plain DB scan so search works everywhere
        // without depending on a search service.
        //
        // Read via config() not env() — env() returns null after
        // `php artisan config:cache`, which would silently force the
        // DB-LIKE fallback even when Typesense is correctly configured.
        $engine = config('scout.driver');
        $useScout = $engine && ! in_array($engine, ['database', 'collection', 'null'], true);

        // Phase G: agent scope on both branches. Scout takes ->where(...)
        // filters that translate to the upstream engine's filter syntax;
        // a null agent yields no results (matches the model scope).
        if ($agentId === null) {
            return collect();
        }

        if ($useScout) {
            $messages = Message::search($query)
                ->where('team_id', $teamId)
                ->where('agent_id', $agentId)
                ->take(50)
                ->get()
                ->load('conversation:id,lead_id,voiceflow_user_id');
        } else {
            $messages = Message::query()
                ->where('team_id', $teamId)
                ->forAgent($agentId)
                ->where('text', 'like', '%'.$query.'%')
                ->latest('sent_at')
                ->limit(50)
                ->with('conversation:id,lead_id,voiceflow_user_id')
                ->get();
        }

        return $messages->map(fn (Message $m) => [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'role' => $m->role,
            'text' => $m->text,
            'sent_at' => optional($m->sent_at)->toIso8601String(),
            'lead_id' => $m->conversation?->lead_id,
        ])->values();
    }
}
