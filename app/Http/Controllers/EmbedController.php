<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Events\LeadMessage;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Conversation;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Models\RuntimeSession;
use App\Services\ConversationRecorder;
use App\Support\Embed\DomainAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

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

        // Returning visitor with a live session → resume: restore the
        // transcript, no greeting, no LLM call, no charge.
        if ($this->runtime->hasSession($agent, $visitorId)) {
            return $this->launchResponse($slug, $visitorId, [
                'visitor_id' => $visitorId,
                'agent_name' => $agent->name,
                'resumed' => true,
                'transcript' => $this->runtime->transcript($agent, $visitorId),
                'traces' => [],
            ]);
        }

        // Greeting cap: launches are normally free (the visitor hasn't said
        // anything yet), which makes them a token-burn vector for bots
        // spread across IPs (the per-IP throttle alone can't see that).
        // Past the daily allowance, a launch debits the tier multiplier —
        // real traffic spikes keep working, paid for.
        $cap = max(0, (int) config('runtime.safety.free_greetings_per_day'));
        $greetings = (int) Cache::increment($this->greetingCounterKey($team->id));
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
        $conversation = $this->recordConversation($agent, $visitorId);
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $this->recordMessage($conversation, 'agent', $text, (array) ($trace['payload']['citations'] ?? []));
            $this->broadcastEmbed($team->id, 'agent', $text);
        }

        return $this->launchResponse($slug, $visitorId, [
            'visitor_id' => $visitorId,
            'agent_name' => $agent->name,
            'resumed' => false,
            'transcript' => [],
            'traces' => $traces,
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
        $conversation = $this->recordConversation($agent, $data['visitor_id']);
        $this->recordMessage($conversation, 'user', $data['message']);
        $this->broadcastEmbed($team->id, 'user', $data['message']);

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
            $this->broadcastEmbed($team->id, 'agent', $text);
        }

        // Terminal flow state → mark the conversation ended (dashboard replay).
        if ($conversation !== null && $this->sessionEnded($agent, $data['visitor_id'])) {
            rescue(fn () => $this->recorder->end($conversation), report: true);
        }

        return response()->json([
            'traces' => $traces,
        ]);
    }

    /**
     * Resolve (find-or-create) the operator-visible conversation for an embed
     * visitor. Best-effort — recording must never break the visitor's chat.
     */
    protected function recordConversation(Agent $agent, string $visitorId): ?Conversation
    {
        try {
            return $this->recorder->resolve(
                teamId: (int) $agent->team_id,
                visitorId: $visitorId,
                channel: 'embed',
                agentId: $agent->id,
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

    protected function broadcastEmbed(int $teamId, string $role, string $text): void
    {
        rescue(fn () => broadcast(new LeadMessage(
            teamId: $teamId,
            leadId: null,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
        )), report: false);
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
