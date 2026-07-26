<?php

namespace App\Http\Controllers;

use App\Events\LeadMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Runtime\Models\RuntimeSession;
use App\Services\ConversationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /** Messages loaded per transcript page (newest window first, then lazy "load earlier"). */
    private const TRANSCRIPT_PAGE = 50;

    public function __construct(protected ConversationRecorder $recorder) {}

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

        $teamId = $team->id;
        $agentId = $team->current_agent_id;

        // Inline filters (the standalone Search page was merged in here): a
        // keyword box that scans visitor id / lead name+email / message text,
        // plus channel / status / rating dropdowns. All agent-scoped.
        $q = trim((string) $request->input('q', ''));
        $channel = trim((string) $request->input('channel', ''));
        $status = trim((string) $request->input('status', ''));
        $ratingFilter = trim((string) $request->input('rating', ''));

        // Phase G: agent-scoped so switching agents swaps the conversation
        // list. forAgent(null) returns no rows (no current agent = nothing to show).
        $conversations = Conversation::query()
            ->where('team_id', $teamId)
            ->forAgent($agentId)
            ->when($leadFilter, fn ($query) => $query->where('lead_id', $leadFilter->id))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('visitor_id', 'like', $like)
                        ->orWhereHas('lead', fn ($l) => $l
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like))
                        ->orWhereHas('messages', fn ($m) => $m->where('text', 'like', $like));
                });
            })
            ->when($channel !== '', fn ($query) => $query->where('channel', $channel))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            // "Needs human": escalated and still open — the never-miss-a-lead view.
            ->when($request->boolean('needs_human'), fn ($query) => $query
                ->where('meta->handoff_requested', true)
                ->where('status', '!=', 'ended'))
            ->when(
                in_array($ratingFilter, Conversation::RATINGS, true),
                fn ($query) => $query->where('rating', $ratingFilter),
            )
            ->with('lead:id,name,email')
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        // Channel dropdown options: the distinct channels this agent actually
        // has conversations on (so the filter only offers real values).
        $channelOptions = $agentId === null ? [] : Conversation::query()
            ->where('team_id', $teamId)
            ->forAgent($agentId)
            ->distinct()
            ->orderBy('channel')
            ->pluck('channel')
            ->all();

        // Operator reference: the last 5 rated conversations for the current
        // agent (most recently rated first). Scoped per agent to match the
        // list above — switching agents swaps which feedback shows. Built via
        // the query builder (stdClass rows) so the lead-name join and the
        // shaping stay clear of Eloquent's dynamic-property type noise.
        $feedback = [];
        if ($agentId !== null) {
            $rows = DB::table('conversations')
                ->leftJoin('leads', 'leads.id', '=', 'conversations.lead_id')
                ->where('conversations.team_id', $teamId)
                ->where('conversations.agent_id', $agentId)
                ->whereNotNull('conversations.rating')
                ->orderByDesc('conversations.rated_at')
                ->limit(5)
                ->get([
                    'conversations.id',
                    'conversations.visitor_id',
                    'conversations.rating',
                    'conversations.feedback_comment',
                    'conversations.rated_at',
                    'leads.name as lead_name',
                ]);

            foreach ($rows as $r) {
                $feedback[] = [
                    'id' => $r->id,
                    'name' => $r->lead_name ?? $r->visitor_id,
                    'rating' => $r->rating,
                    'comment' => $r->feedback_comment,
                    'rated_at' => $r->rated_at
                        ? Carbon::parse($r->rated_at)->toIso8601String()
                        : null,
                ];
            }
        }

        return Inertia::render('Conversations/Index', [
            'conversations' => $conversations,
            'feedback' => $feedback,
            'filters' => [
                'q' => $q,
                'channel' => $channel,
                'status' => $status,
                'rating' => $ratingFilter,
                'needs_human' => $request->boolean('needs_human'),
            ],
            'channel_options' => $channelOptions,
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

        // Load only the most recent window; older turns lazy-load via messages().
        // Pulled newest-first then reversed so the page renders oldest→newest.
        $messages = $conversation->messages()
            ->orderByDesc('sequence')
            ->limit(self::TRANSCRIPT_PAGE)
            ->get()
            ->reverse()
            ->values();

        $oldestLoaded = $messages->first();
        $hasMore = $oldestLoaded !== null
            && $conversation->messages()
                ->where('sequence', '<', $oldestLoaded->getAttribute('sequence'))
                ->exists();

        return Inertia::render('Conversations/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'messagesHasMore' => $hasMore,
        ]);
    }

    /**
     * POST /conversations/{conversation}/reply — human takeover.
     *
     * A team member replies to the visitor from the dashboard. The first
     * reply takes the conversation over: the AI stands down (embed interact
     * short-circuits, no LLM/credits) until release() hands it back. The
     * reply reaches the visitor via the widget's poll loop and the dashboard
     * via the team broadcast channel.
     */
    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->getAttribute('team_id') === $team->getAttribute('id'), 403);
        abort_if($conversation->getAttribute('status') === 'ended', 422, 'This conversation has ended.');

        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $text = trim($data['message']);

        $meta = (array) ($conversation->meta ?? []);
        $meta['takeover_at'] ??= now()->toIso8601String();
        $meta['handoff_requested'] = true;
        $meta['human_takeover'] = true;
        $meta['takeover_by'] = (string) $request->user()->name;
        $conversation->meta = $meta;
        $conversation->save();

        $message = $this->recorder->record($conversation, 'human', $text);

        // Keep the LLM's session history coherent for a later release: the
        // teammate's words become an assistant turn (merged when consecutive,
        // so Anthropic's role-alternation rule survives multiple replies).
        rescue(fn () => $this->appendTeamTurn($conversation, (string) $request->user()->name, $text), report: true);

        rescue(fn () => broadcast(new LeadMessage(
            teamId: (int) $conversation->getAttribute('team_id'),
            leadId: $conversation->getAttribute('lead_id'),
            role: 'human',
            text: $text,
            at: now()->toIso8601String(),
            conversationId: (int) $conversation->getAttribute('id'),
        )), report: false);

        return response()->json([
            'message' => [
                'role' => 'human',
                'text' => $text,
                'sequence' => $message->getAttribute('sequence'),
                'sent_at' => $message->getAttribute('sent_at'),
            ],
            'takeover' => true,
        ]);
    }

    /**
     * POST /conversations/{conversation}/release — hand the conversation
     * back to the AI. The next visitor message runs through the runtime
     * again (with the teammate's replies in its context).
     */
    public function release(Request $request, Conversation $conversation): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->getAttribute('team_id') === $team->getAttribute('id'), 403);

        $meta = (array) ($conversation->meta ?? []);
        $meta['human_takeover'] = false;
        // Clear the escalation flag too: the human handled it and is handing
        // back. Left set, it keeps the conversation in the "Needs human" queue
        // forever, keeps the widget polling /poll indefinitely, and keeps the
        // zero-cost canned/FAQ path disabled for the rest of the session.
        $meta['handoff_requested'] = false;
        $meta['released_at'] = now()->toIso8601String();
        $conversation->meta = $meta;
        $conversation->save();

        return response()->json(['takeover' => false, 'handoff' => false]);
    }

    /**
     * Merge the teammate's reply into the runtime session history as an
     * assistant turn, concatenating consecutive assistant entries so the
     * replayed history keeps alternating roles.
     */
    protected function appendTeamTurn(Conversation $conversation, string $name, string $text): void
    {
        $session = RuntimeSession::query()
            ->where('agent_id', $conversation->getAttribute('agent_id'))
            ->where('visitor_id', $conversation->getAttribute('visitor_id'))
            ->first();

        if ($session === null) {
            return;
        }

        $entry = "[Your human teammate {$name} replied to the visitor] {$text}";
        $history = (array) ($session->history ?? []);
        $last = $history === [] ? null : $history[count($history) - 1];

        if (is_array($last) && ($last['role'] ?? '') === 'assistant' && is_string($last['content'] ?? null)) {
            $history[count($history) - 1]['content'] = $last['content']."\n\n".$entry;
        } else {
            $history[] = ['role' => 'assistant', 'content' => $entry];
        }

        $session->history = $history;
        $session->save();
    }

    /**
     * Older transcript turns (lazy "load earlier"). Returns up to one page of
     * messages strictly before the given sequence cursor, oldest→newest.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($conversation->getAttribute('team_id') === $team->getAttribute('id'), 403);
        abort_unless(
            $conversation->getAttribute('agent_id') === $team->getAttribute('current_agent_id'),
            404,
        );

        $before = $request->integer('before');

        $batch = $conversation->messages()
            ->when($before > 0, fn ($q) => $q->where('sequence', '<', $before))
            ->orderByDesc('sequence')
            ->limit(self::TRANSCRIPT_PAGE)
            ->get();

        $oldest = $batch->last(); // newest-first, so last() is the oldest in the batch
        $hasMore = $oldest !== null
            && $conversation->messages()
                ->where('sequence', '<', $oldest->getAttribute('sequence'))
                ->exists();

        return response()->json([
            'messages' => $batch->reverse()->values(),
            'has_more' => $hasMore,
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
}
