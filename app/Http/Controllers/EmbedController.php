<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
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

        $iframeUrl = url("/embed/{$slug}");
        $primaryColor = '#6366f1'; // indigo-500; future: read from request data-attributes

        $js = view('embed.widget', [
            'iframeUrl' => $iframeUrl,
            'primaryColor' => $primaryColor,
            'agentName' => $agent->name,
        ])->render();

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Public cache, 5 min — short enough that color/iframe URL
            // changes propagate quickly, long enough to avoid hammering us.
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
    public function chat(string $slug): Response
    {
        $agent = $this->resolveAgent($slug);

        return response(view('embed.chat', [
            'slug' => $slug,
            'agentName' => $agent->name,
        ])->render(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The customer can iframe us from any domain.
            'Content-Security-Policy' => 'frame-ancestors *;',
            'X-Frame-Options' => 'ALLOWALL', // legacy fallback for older browsers
        ]);
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

        $team = $agent->team;
        if (! $team instanceof Team) {
            return response()->json(['error' => 'Agent misconfigured.'], 503);
        }
        if (! $team->hasCredits(1)) {
            return response()->json([
                'error' => "This agent isn't available right now. Please try again later.",
            ], 402);
        }

        $visitorId = $request->cookie("fs_embed_{$slug}");
        if (! is_string($visitorId) || $visitorId === '') {
            $visitorId = 'embed-'.Str::random(28);
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
            return response()->json([
                'error' => 'The agent is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'visitor_id' => $visitorId,
            'agent_name' => $agent->name,
            'traces' => $traces,
        ])->cookie(Cookie::make(
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

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
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

        try {
            $traces = $this->runtime->sendText($agent, $data['visitor_id'], $data['message']);
        } catch (RuntimeException $e) {
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

        return response()->json([
            'traces' => $traces,
        ]);
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
