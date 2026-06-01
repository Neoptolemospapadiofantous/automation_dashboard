<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
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
        $teamId = $request->user()->currentTeam->id;

        $conversations = Conversation::query()
            ->where('team_id', $teamId)
            ->with('lead:id,name,email')
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Conversations/Index', [
            'conversations' => $conversations,
        ]);
    }

    /**
     * Show a single conversation with its full transcript.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        abort_unless($conversation->team_id === $request->user()->currentTeam->id, 403);

        $conversation->load('lead:id,name,email');
        $messages = $conversation->messages()->orderBy('sequence')->get();

        return Inertia::render('Conversations/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Search messages across the team's conversations.
     *
     * Uses Scout (Typesense hybrid keyword + semantic search) when configured;
     * falls back to a MySQL LIKE/fulltext scan otherwise so it works everywhere.
     */
    public function search(Request $request): Response
    {
        $teamId = $request->user()->currentTeam->id;
        $query = trim((string) $request->input('q', ''));

        $results = collect();

        if ($query !== '') {
            $results = $this->runSearch($teamId, $query);
        }

        return Inertia::render('Conversations/Search', [
            'q' => $query,
            'results' => $results,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function runSearch(int $teamId, string $query): Collection
    {
        $driver = config('scout.driver');

        if ($driver && $driver !== 'null') {
            // Scout path (Typesense, etc.) — team-scoped.
            $messages = Message::search($query)
                ->where('team_id', $teamId)
                ->take(50)
                ->get()
                ->load('conversation:id,lead_id,voiceflow_user_id');
        } else {
            // Fallback: plain DB scan (works on SQLite/MySQL with no engine).
            $messages = Message::query()
                ->where('team_id', $teamId)
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
