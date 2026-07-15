<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Events\LeadMessage;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\RuntimeUsage;
use App\Models\Team;
use App\Runtime\Canned\CannedAnswers;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Models\RuntimeSession;
use App\Services\ConversationRecorder;
use App\Support\Embed\DomainAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public chat embed: a JS snippet customers paste into their own website
 * → a floating button → an iframe that opens the chat → back to our
 * backend through the native runtime.
 *
 * Endpoints are unauthenticated (the JS runs on someone else's site).
 * Authorization is per-agent-slug: anyone with the slug can embed,
 * but the agent must be `active`.
 *
 * Billing matches the dashboard's documented basis: (1 per visitor
 * message + 1 per agent reply) × the agent's quality-tier multiplier.
 * launch() greetings are free up to a per-team daily allowance, then
 * debit the multiplier (anti-abuse, see below).
 *
 * Visitor identity: a 30-day cookie scoped to the embed flow. The
 * visitor doesn't have a Flowstack account; the cookie is just a
 * stable user_id so the conversation has continuity.
 */
class EmbedController extends Controller
{
    public function __construct(
        protected CreditMeter $credits,
        protected Runtime $runtime,
        protected ConversationRecorder $recorder,
    ) {}

    /**
     * GET /widget/{slug}.js
     *
     * Returns the vanilla JS that creates the floating button + opens
     * the iframe. Loaded with <script src=".../widget/{slug}.js" defer>.
     * Public, cached aggressively at the edge.
     */
    public function widget(string $slug): Response
    {
        $agent = $this->resolveAgent($slug);

        $js = view('embed.widget', [
            'iframeUrl' => url("/embed/{$slug}"),
            'agentName' => $agent->name,
            'config' => $agent->widgetConfig(),
            // The loader self-checks window.location.hostname against this
            // (UX layer); the hard enforcement is frame-ancestors in chat().
            'allowedDomains' => $agent->allowedDomains(),
        ])->render();

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Public cache, 5 min — short enough that config changes
            // propagate quickly, long enough to avoid hammering us.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * GET /embed/{slug}
     *
     * Standalone HTML chat page loaded inside the iframe. No AppLayout,
     * no Inertia — plain Blade so customers' sites don't pay the cost
     * of our SPA bundle. CSP-friendly: no inline scripts beyond the
     * tiny bootstrap.
     */
    public function chat(string $slug, Request $request): Response
    {
        $agent = $this->resolveAgent($slug);
        $domains = $agent->allowedDomains();

        // Browser-enforced, unspoofable control over WHERE the chat may be
        // embedded: an allowlisted agent's iframe simply won't render on a
        // disallowed parent domain. Empty allowlist → embeddable anywhere.
        $frameAncestors = DomainAllowlist::frameAncestors($domains);

        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "frame-ancestors {$frameAncestors};",
        ];
        // Only emit the legacy ALLOWALL when truly unrestricted; with an
        // allowlist we rely on frame-ancestors (X-Frame-Options can't express
        // multiple origins).
        if ($domains === []) {
            $headers['X-Frame-Options'] = 'ALLOWALL';
        }

        return response(view('embed.chat', [
            'slug' => $slug,
            'agentName' => $agent->name,
            'config' => $agent->widgetConfig(),
            // Parent host forwarded by the loader (?ref=) — echoed back on
            // launch/interact for the best-effort origin check.
            'host' => DomainAllowlist::host((string) $request->query('ref', '')) ?? '',
        ])->render(), 200, $headers);
    }

    /**
     * POST /embed/{slug}/launch
     *
     * Starts an engine session for the embedded visitor. Returns the
     * welcome traces from the agent. Visitor ID lives in a cookie so
     * subsequent interact() calls thread the same session.
     */
    public function launch(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $team = $agent->team;
        if (! $team instanceof Team) {
            return response()->json(['error' => 'Agent misconfigured.'], 503);
        }
        if (! $team->hasCredits(1)) {
            return response()->json([
                'error' => "This agent isn't available right now. Please try again later.",
            ], 402);
        }

        // Visitor identity: prefer the client-supplied id (localStorage —
        // survives third-party-cookie blocking + http localhost), then the
        // cookie, else mint one. The id is an unguessable capability token
        // ("embed-" + 28 random chars); validate the shape so a client can't
        // inject an arbitrary value.
        $visitorId = $this->resolveVisitorId(
            (string) $request->input('visitor_id', ''),
            (string) $request->cookie("fs_embed_{$slug}", ''),
        );

        // Stable browser identity (survives reset) — groups this visitor's
        // chats so the widget's home screen can list their recent ones.
        $visitorToken = $this->resolveVisitorToken((string) $request->input('visitor_token', ''));

        // Returning visitor with a live session → resume: restore the
        // transcript, no greeting, no LLM call, no charge. Handoff/takeover
        // state rides along so the widget resumes polling for team replies.
        if ($this->runtime->hasSession($agent, $visitorId)) {
            $conversation = $this->latestConversation($agent, $visitorId);

            return $this->launchResponse($slug, $visitorId, [
                'visitor_id' => $visitorId,
                'agent_name' => $agent->name,
                'resumed' => true,
                'transcript' => $this->runtime->transcript($agent, $visitorId),
                'traces' => [],
                'chips' => CannedAnswers::forAgent($agent->id)->chips(),
                'handoff' => (bool) ($conversation->meta['handoff_requested'] ?? false),
                'takeover' => $this->takeoverActive($conversation),
                'last_seq' => $this->lastHumanSequence($conversation),
            ]);
        }

        // Greeting cap: launches are normally free (the visitor hasn't said
        // anything yet), which makes them a token-burn vector for bots
        // spread across IPs (the per-IP throttle alone can't see that).
        // Past the daily allowance, a launch debits the tier multiplier —
        // real traffic spikes keep working, paid for. Agents with a
        // published static greeting are exempt: their launch never reaches
        // an LLM (AgentRuntime serves the greeting verbatim), so there are
        // no tokens to protect and nothing to charge for.
        $staticGreeting = trim((string) ((AgentConfigVersion::publishedConfig($agent->id) ?? [])['greeting'] ?? '')) !== '';
        $cap = max(0, (int) config('runtime.safety.free_greetings_per_day'));
        $greetings = $staticGreeting ? 0 : (int) Cache::increment($this->greetingCounterKey($team->id));
        if ($greetings === 1) {
            // First hit today sets the expiry; increments don't touch TTL.
            Cache::put($this->greetingCounterKey($team->id), 1, now()->addDays(2));
        }
        if ($greetings > $cap) {
            try {
                $this->credits->consume(team: $team, amount: AgentConfigVersion::creditsPerMessage($agent->id), agentId: $agent->id, meta: ['embed' => true, 'greeting_over_cap' => true]);
            } catch (OutOfCredits) {
                return response()->json([
                    'error' => "This agent isn't available right now. Please try again later.",
                ], 402);
            }
        }

        // Routed through the Runtime contract (AppServiceProvider binds
        // the native engine).
        try {
            $traces = $this->runtime->launch($agent, $visitorId);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => 'The agent is temporarily unavailable.',
            ], 503);
        }

        // Record the greeting to the operator-visible transcript + broadcast
        // (so widget conversations show up in the dashboard like in-app chats).
        $conversation = $this->recordConversation($agent, $visitorId, $visitorToken);
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $this->recordMessage($conversation, 'agent', $text, (array) ($trace['payload']['citations'] ?? []));
            $this->broadcastEmbed($team->id, 'agent', $text, $conversation?->id);
        }

        return $this->launchResponse($slug, $visitorId, [
            'visitor_id' => $visitorId,
            'agent_name' => $agent->name,
            'resumed' => false,
            'transcript' => [],
            'traces' => $traces,
            'chips' => CannedAnswers::forAgent($agent->id)->chips(),
            'handoff' => false,
            'takeover' => false,
            'last_seq' => 0,
        ]);
    }

    /**
     * Resolve the visitor id from a client-supplied value then the cookie,
     * minting a fresh token when neither is a valid id. The id is a bearer
     * capability for an anonymous chat — accept only the exact shape we mint.
     */
    protected function resolveVisitorId(string $fromClient, string $fromCookie): string
    {
        foreach ([$fromClient, $fromCookie] as $candidate) {
            if (preg_match('/^embed-[A-Za-z0-9]{16,48}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'embed-'.Str::random(28);
    }

    /**
     * Validate the stable visitor token (persistent browser identity). Same
     * shape as a visitor id; returns null when absent or malformed so a bad
     * value just drops the history grouping rather than breaking the chat.
     */
    protected function resolveVisitorToken(string $candidate): ?string
    {
        return preg_match('/^embed-[A-Za-z0-9]{16,48}$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function launchResponse(string $slug, string $visitorId, array $payload): JsonResponse
    {
        return response()->json($payload)->cookie(Cookie::make(
            name: "fs_embed_{$slug}",
            value: $visitorId,
            minutes: 60 * 24 * 30, // 30 days
            sameSite: 'none',
            secure: true,
            httpOnly: true,
        ));
    }

    /**
     * POST /embed/{slug}/interact
     *
     * Sends a visitor message to the engine + returns the agent's traces.
     * Consumes credits from the agent's team.
     */
    public function interact(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'host' => ['nullable', 'string', 'max:255'],
        ]);

        $team = $agent->team;
        if (! $team instanceof Team) {
            return response()->json(['error' => 'Agent misconfigured.'], 503);
        }

        $visitorToken = $this->resolveVisitorToken((string) $request->input('visitor_token', ''));
        $conversation = $this->recordConversation($agent, $data['visitor_id'], $visitorToken);

        // Human takeover: a teammate owns this conversation. Record + relay
        // the visitor's message to the dashboard and skip the AI entirely —
        // no LLM call, no credit charge. Team replies reach the visitor via
        // the poll endpoint.
        if ($this->takeoverActive($conversation)) {
            $this->recordMessage($conversation, 'user', $data['message']);
            $this->broadcastEmbed($team->id, 'user', $data['message'], $conversation?->id);

            return response()->json([
                'traces' => [],
                'ended' => false,
                'handoff' => true,
                'takeover' => true,
            ]);
        }

        // Deterministic FAQ shortcut FIRST: a chip tap or a keyword-matched
        // question is answered from stored config with no LLM call and no
        // credit charge. Checked before the credit pre-check on purpose, so
        // canned answers keep working even when the team is out of credits.
        // SKIPPED once the conversation is escalated: a handoff turn belongs
        // to the LLM (it's mid contact-capture) — live-tested 2026-07-10, a
        // visitor's "call me about the Operator plan" contact reply keyword-
        // matched the Pricing chip and the lead was silently lost.
        $handoffPending = (bool) (($conversation->meta ?? [])['handoff_requested'] ?? false);
        if (! $handoffPending && ($canned = CannedAnswers::forAgent($agent->id)->match($data['message']))) {
            $this->recordMessage($conversation, 'user', $data['message']);
            $this->broadcastEmbed($team->id, 'user', $data['message'], $conversation?->id);
            $this->recordMessage($conversation, 'agent', $canned->answer);
            $this->broadcastEmbed($team->id, 'agent', $canned->answer, $conversation?->id);

            // Deflection-rate bookkeeping for the analytics page — a canned
            // turn cost zero tokens/credits, and that saving should be visible.
            rescue(fn () => RuntimeUsage::recordCanned($agent, AgentConfigVersion::publishedTier($agent->id)), report: false);

            return response()->json([
                'traces' => [['type' => 'text', 'payload' => ['message' => $canned->answer, 'citations' => [], 'canned' => true]]],
                'ended' => false,
                'handoff' => (bool) ($conversation?->meta['handoff_requested'] ?? false),
                'takeover' => false,
            ]);
        }

        // Pre-check only — the debit happens AFTER the engine replies so the
        // billing basis matches the documented rate (1 per visitor message
        // + 1 per agent reply, × the quality-tier multiplier) and users
        // aren't charged for failures.
        $multiplier = AgentConfigVersion::creditsPerMessage($agent->id);
        if (! $team->hasCredits($multiplier)) {
            return response()->json([
                'error' => "This agent isn't available right now. Please try again later.",
            ], 402);
        }

        // Record + echo the visitor's message before the engine call, so the
        // dashboard transcript + lead board update live.
        $this->recordMessage($conversation, 'user', $data['message']);
        $this->broadcastEmbed($team->id, 'user', $data['message'], $conversation?->id);

        try {
            $traces = $this->runtime->sendText($agent, $data['visitor_id'], $data['message']);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => 'The agent is temporarily unavailable.',
            ], 503);
        }

        $replies = count(array_filter($traces, fn (array $t) => (string) ($t['payload']['message'] ?? '') !== ''));
        try {
            $this->credits->consume(
                team: $team,
                amount: (1 + $replies) * $multiplier,
                agentId: $agent->id,
                meta: ['embed' => true],
            );
        } catch (OutOfCredits) {
            // Post-reply race — the turn already happened; flag for ops and
            // let the NEXT turn fail the pre-check.
            report(new \RuntimeException('Credit debit raced past zero for team '.$team->id.' (embed)'));
        }

        // Record the agent's reply (with citations) + broadcast.
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $this->recordMessage($conversation, 'agent', $text, (array) ($trace['payload']['citations'] ?? []));
            $this->broadcastEmbed($team->id, 'agent', $text, $conversation?->id);
        }

        // Terminal flow state → mark the conversation ended (dashboard replay)
        // and signal the widget to show the post-chat rating prompt.
        $ended = $this->sessionEnded($agent, $data['visitor_id']);
        if ($conversation !== null && $ended) {
            rescue(fn () => $this->recorder->end($conversation), report: true);
        }

        // Handoff may have been flagged DURING this turn (the tool or the
        // low-confidence backstop) — re-read the conversation row so the
        // widget learns to start polling for team replies immediately.
        $handoff = false;
        if ($conversation !== null) {
            $conversation->refresh();
            $handoff = (bool) ($conversation->meta['handoff_requested'] ?? false);
        }

        return response()->json([
            'traces' => $traces,
            'ended' => $ended,
            'handoff' => $handoff,
            'takeover' => false,
        ]);
    }

    /**
     * POST /embed/{slug}/feedback
     *
     * Records the visitor's post-chat rating (bad|ok|good) + optional comment
     * on their current conversation, then ends it. Unauthenticated like the
     * rest of the embed flow; scoped to the visitor's own conversation by the
     * (team, visitor_id) pair. Skipping a rating never calls this endpoint.
     */
    public function feedback(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'string', Rule::in(Conversation::RATINGS)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // The visitor can only rate a conversation they own. No match (already
        // reset, or never recorded) → succeed quietly so the widget still resets.
        $conversation = Conversation::query()
            ->where('team_id', $agent->team_id)
            ->where('visitor_id', $data['visitor_id'])
            ->first();

        if ($conversation !== null) {
            rescue(fn () => $this->recorder->rate(
                $conversation,
                $data['rating'],
                $data['comment'] ?? null,
            ), report: true);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /embed/{slug}/history
     *
     * The visitor's own recent conversations (last 5) for the widget's home
     * screen. Scoped to (team, visitor_token): the token is the stable browser
     * identity and acts as the bearer capability, so a visitor only ever sees
     * their own chats. Pre-token rows (null visitor_token) never match.
     */
    /**
     * POST /embed/{slug}/poll
     *
     * The visitor side of human takeover. The widget polls this (only while
     * handoff/takeover is active) to receive TEAM-MEMBER replies, which —
     * unlike AI replies — are not responses to the visitor's own requests.
     * Returns only 'human'-role messages after the client's cursor, so AI
     * replies (delivered inline via interact) are never duplicated.
     */
    public function poll(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:255'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->latestConversation($agent, (string) $data['visitor_id']);
        if ($conversation === null) {
            return response()->json(['messages' => [], 'handoff' => false, 'takeover' => false, 'ended' => false]);
        }

        $messages = DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->where('role', 'human')
            ->where('sequence', '>', (int) ($data['after'] ?? 0))
            ->orderBy('sequence')
            ->limit(50)
            ->get(['role', 'text', 'sequence', 'sent_at']);

        return response()->json([
            'messages' => $messages,
            'handoff' => (bool) (($conversation->meta ?? [])['handoff_requested'] ?? false),
            'takeover' => $this->takeoverActive($conversation),
            'ended' => $conversation->status === 'ended',
        ]);
    }

    public function history(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'regex:/^embed-[A-Za-z0-9]{16,48}$/'],
        ]);

        $conversations = Conversation::query()
            ->where('team_id', $agent->team_id)
            ->where('visitor_token', $data['visitor_token'])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        $items = [];
        foreach ($conversations as $conversation) {
            $items[] = [
                'id' => $conversation->id,
                'title' => $this->conversationTitle($conversation),
                'status' => $conversation->status,
                'rating' => $conversation->rating,
                'message_count' => $conversation->message_count,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            ];
        }

        return response()->json(['conversations' => $items]);
    }

    /**
     * POST /embed/{slug}/conversation
     *
     * Read-only transcript of one of the visitor's past conversations, for the
     * home screen's "reopen" view. Token-scoped: the conversation must belong
     * to the (team, visitor_token) pair or it's a 404 — the id alone is not
     * authority (ids are sequential and guessable).
     */
    public function transcript(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        if ($denied = $this->originDenied($agent, $request)) {
            return $denied;
        }

        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'regex:/^embed-[A-Za-z0-9]{16,48}$/'],
            'conversation_id' => ['required', 'integer'],
        ]);

        $conversation = Conversation::query()
            ->where('team_id', $agent->team_id)
            ->where('visitor_token', $data['visitor_token'])
            ->find($data['conversation_id']);

        if ($conversation === null) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        // Query-builder rows (stdClass) so the transcript shaping stays clear of
        // Eloquent's dynamic-property type noise (matches the dashboard list).
        $rows = DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'agent', 'human'])
            ->orderBy('sequence')
            ->get(['role', 'text', 'sent_at']);

        $messages = [];
        foreach ($rows as $row) {
            $text = trim((string) $row->text);
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'role' => $row->role,
                'text' => $row->text,
                'sent_at' => $row->sent_at
                    ? Carbon::parse($row->sent_at)->toIso8601String()
                    : null,
            ];
        }

        return response()->json([
            'id' => $conversation->id,
            'status' => $conversation->status,
            'rating' => $conversation->rating,
            'messages' => $messages,
        ]);
    }

    /**
     * A short, human-readable label for a conversation: the visitor's first
     * message, trimmed. Falls back to a generic label for greeting-only chats.
     */
    protected function conversationTitle(Conversation $conversation): string
    {
        $first = (string) $conversation->messages()
            ->where('role', 'user')
            ->orderBy('sequence')
            ->value('text');

        $first = trim($first);

        return $first === '' ? 'Conversation' : Str::limit($first, 60);
    }

    /**
     * Resolve (find-or-create) the operator-visible conversation for an embed
     * visitor. Best-effort — recording must never break the visitor's chat.
     */
    protected function recordConversation(Agent $agent, string $visitorId, ?string $visitorToken = null): ?Conversation
    {
        try {
            return $this->recorder->resolve(
                teamId: (int) $agent->team_id,
                visitorId: $visitorId,
                channel: 'embed',
                agentId: $agent->id,
                visitorToken: $visitorToken,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    protected function recordMessage(?Conversation $conversation, string $role, string $text, array $citations = []): void
    {
        if ($conversation === null) {
            return;
        }
        try {
            $this->recorder->record($conversation, $role, $text, 'text', null, $citations ?: null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function broadcastEmbed(int $teamId, string $role, string $text, ?int $conversationId = null): void
    {
        rescue(fn () => broadcast(new LeadMessage(
            teamId: $teamId,
            leadId: null,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
            conversationId: $conversationId,
        )), report: false);
    }

    /**
     * A teammate has taken over this conversation from the dashboard — the
     * AI stays out until they release it.
     */
    protected function takeoverActive(?Conversation $conversation): bool
    {
        return $conversation !== null
            && (bool) (($conversation->meta ?? [])['human_takeover'] ?? false)
            && $conversation->status !== 'ended';
    }

    protected function latestConversation(Agent $agent, string $visitorId): ?Conversation
    {
        return Conversation::query()
            ->where('team_id', $agent->team_id)
            ->where('visitor_id', $visitorId)
            ->latest('id')
            ->first();
    }

    /**
     * Highest sequence among team-member ('human') messages — the widget's
     * polling cursor, so replies are never re-delivered.
     */
    protected function lastHumanSequence(?Conversation $conversation): int
    {
        if ($conversation === null) {
            return 0;
        }

        return (int) DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->where('role', 'human')
            ->max('sequence');
    }

    protected function sessionEnded(Agent $agent, string $visitorId): bool
    {
        return RuntimeSession::query()
            ->where('agent_id', $agent->id)
            ->where('visitor_id', $visitorId)
            ->value('flow_state') === 'ended';
    }

    /**
     * Best-effort domain check for launch/interact. The hard control is the
     * frame-ancestors CSP in chat(); this catches direct (non-iframe) calls
     * that forward (or omit) a host. Empty allowlist → always allowed.
     *
     * Host source order: the loader-forwarded `host` field, then Referer,
     * then Origin. All are spoofable by a hand-crafted request — the
     * throttle + greeting cap + credit ceiling are the abuse backstops.
     */
    protected function originDenied(Agent $agent, Request $request): ?JsonResponse
    {
        $domains = $agent->allowedDomains();
        if ($domains === []) {
            return null;
        }

        $candidate = (string) $request->input('host', '')
            ?: (string) $request->headers->get('referer', '')
            ?: (string) $request->headers->get('origin', '');

        if (! DomainAllowlist::allows($domains, $candidate)) {
            return response()->json([
                'error' => 'This widget is not authorized on this domain.',
            ], 403);
        }

        return null;
    }

    protected function greetingCounterKey(int $teamId): string
    {
        return 'embed_greetings:'.$teamId.':'.now()->format('Ymd');
    }

    protected function resolveAgent(string $slug): Agent
    {
        $agent = Agent::query()
            ->where('slug', $slug)
            ->where('status', Agent::STATUS_ACTIVE)
            ->first();

        if ($agent === null) {
            abort(404, 'Agent not found or inactive.');
        }

        return $agent;
    }
}
