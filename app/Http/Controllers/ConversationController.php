<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
}
