<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Models\Agent;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Public chat embed: a JS snippet customers paste into their own website
 * → a floating button → an iframe that opens the chat → back to our
 * backend via the same agent's Voiceflow project.
 *
 * Endpoints are unauthenticated (the JS runs on someone else's site).
 * Authorization is per-agent-slug: anyone with the slug can embed,
 * but the agent must be `active`. Credit consumption is charged to
 * the agent's team — customers pay for embedded conversations the
 * same way they pay for dashboard chats.
 *
 * Visitor identity: a 30-day cookie scoped to the embed flow. The
 * visitor doesn't have a Flowstack account; the cookie is just a
 * stable user_id for Voiceflow so the conversation has continuity.
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
     * Starts a Voiceflow session for the embedded visitor. Returns the
     * welcome traces from the agent. Visitor ID lives in a cookie so
     * subsequent interact() calls thread the same session.
     */
    public function launch(string $slug, Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($slug);

        $team = $agent->team;
        if ($team instanceof Team && ! $team->hasCredits(1)) {
            return response()->json([
                'error' => "This agent isn't available right now. Please try again later.",
            ], 402);
        }

        $visitorId = $request->cookie("fs_embed_{$slug}");
        if (! is_string($visitorId) || $visitorId === '') {
            $visitorId = 'embed-'.Str::random(28);
        }

        // Routed through the Runtime contract — the binding (AppServiceProvider)
        // returns RuntimeDispatcher, which picks Voiceflow or native engine
        // based on agent.runtime_mode (native is the default for new agents;
        // legacy agents stay on the Voiceflow adapter until migrated).
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
     * Sends a visitor message to Voiceflow + returns the agent's traces.
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

        try {
            $this->credits->consume(team: $team, amount: 1, agentId: $agent->id, meta: ['embed' => true]);
        } catch (OutOfCredits) {
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

        return response()->json([
            'traces' => $traces,
        ]);
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
