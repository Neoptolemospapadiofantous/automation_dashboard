<?php

use App\Http\Controllers\AgentActionsController;
use App\Http\Controllers\AgentAnalyticsController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentFaqController;
use App\Http\Controllers\AgentVersionsController;
use App\Http\Controllers\ArchitectureGraphController;
use App\Http\Controllers\AutomationActivityController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\HermesMetricsController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OwnKeyController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscribeController;
use App\Http\Controllers\SuiteController;
use App\Http\Middleware\RequireAgent;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The app subdomain (app.flowstack.run) has no marketing landing — that
// lives on flowstack.run. Root just routes into the product.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    RequireAgent::class,
])->group(function () {
    // Agent CRUD + onboarding wizard. Both bypass RequireAgent (the
    // middleware whitelists their route name prefixes) so the user can
    // reach them even when no active agent exists yet. The wizard is a
    // single managed flow (intro → start → done) — agents provision on
    // the native runtime instantly, nothing to paste.
    Route::get('/onboarding', [OnboardingController::class, 'intro'])->name('onboarding.intro');
    Route::post('/onboarding/start', [OnboardingController::class, 'startAgent'])
        ->middleware('throttle:5,1')
        ->name('onboarding.start');
    Route::get('/onboarding/done', [OnboardingController::class, 'done'])->name('onboarding.done');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('agents.store');

    // Versioned agent behavior (draft → publish → rollback). MUST be
    // registered before the /agents/{agent} wildcard below — Laravel
    // matches by registration order and the slug binding would swallow
    // /agents/versions and 404 (no agent has slug 'versions').
    Route::get('/agents/versions', [AgentVersionsController::class, 'index'])
        ->name('agents.versions.index');
    Route::post('/agents/versions/draft', [AgentVersionsController::class, 'saveDraft'])
        ->middleware('throttle:60,1')
        ->name('agents.versions.draft');
    Route::post('/agents/versions/publish', [AgentVersionsController::class, 'publish'])
        ->middleware('throttle:30,1')
        ->name('agents.versions.publish');
    Route::post('/agents/versions/{version}/restore', [AgentVersionsController::class, 'restore'])
        ->whereNumber('version')
        ->middleware('throttle:30,1')
        ->name('agents.versions.restore');
    Route::get('/agents/versions/{version}/export', [AgentVersionsController::class, 'export'])
        ->whereNumber('version')
        ->name('agents.versions.export');

    // Operator Actions editor — author the agent's n8n automations. Writes
    // the SAME draft as the behavior editor (under config.automations), so it
    // shares the publish/rollback lifecycle. Registered before the
    // /agents/{agent} wildcard for the same slug-swallowing reason as above.
    Route::get('/agents/actions', [AgentActionsController::class, 'index'])
        ->name('agents.actions.index');
    Route::post('/agents/actions', [AgentActionsController::class, 'save'])
        ->middleware('throttle:60,1')
        ->name('agents.actions.save');

    // Operator FAQ editor — author the agent's deterministic canned answers
    // (served without an LLM call). Writes the SAME draft (config.canned_answers)
    // so it shares the publish/rollback lifecycle. Before the /agents/{agent}
    // wildcard for the same slug-swallowing reason.
    Route::get('/agents/faq', [AgentFaqController::class, 'index'])
        ->name('agents.faq.index');
    Route::post('/agents/faq', [AgentFaqController::class, 'save'])
        ->middleware('throttle:60,1')
        ->name('agents.faq.save');

    // Automation activity — read-only view of the automation_runs audit log
    // for the current agent. Before the /agents/{agent} wildcard.
    Route::get('/agents/activity', [AutomationActivityController::class, 'index'])
        ->name('agents.activity.index');

    // Per-agent analytics. Slug-bound. Lives at /agents/{slug}/analytics so it
    // sits naturally alongside agents.show; must come BEFORE the wildcard
    // agents.show below for the same reasons as versions above.
    Route::get('/agents/{agent}/analytics', [AgentAnalyticsController::class, 'show'])
        ->name('agents.analytics');

    Route::get('/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::put('/agents/{agent}', [AgentController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('agents.update');
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('agents.destroy');
    Route::post('/agents/{agent}/health', [AgentController::class, 'health'])
        ->middleware('throttle:30,1')
        ->name('agents.health');
    Route::put('/current-agent', [AgentController::class, 'switchCurrent'])
        ->middleware('throttle:60,1')
        ->name('current-agent.update');

    // Bring-your-own-key — a Growth-and-above team supplies its own provider
    // API key, and premium engines run on it at 0 credits against a monthly
    // message cap. The plan gate is enforced in the controller too, so a
    // downgrade cannot be replayed around.
    //
    // The three mutating routes are throttled: store and verify each make a
    // live call to the provider to check the key, so they are the one place
    // an authenticated session can drive outbound requests in a loop.
    Route::get('/settings/own-key', [OwnKeyController::class, 'index'])->name('own-key.index');
    Route::post('/settings/own-key', [OwnKeyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('own-key.store');
    Route::post('/settings/own-key/{ownKey}/verify', [OwnKeyController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('own-key.verify');
    Route::delete('/settings/own-key/{ownKey}', [OwnKeyController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('own-key.destroy');

    // Billing — current plan, credit history, top-up purchase.
    // Top-up flow is DEV-MODE (instant grant) until Phase H wires Stripe
    // Checkout. See BillingController::topup for the swap-over plan.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');

    // Stripe subscription checkout — the team's FIRST subscription.
    // The plan constraint must list every self-serve rung: a missing key 404s
    // silently, which is exactly how 'growth' was unreachable between the
    // 2026-08-27 repricing and this route being widened.
    Route::post('/subscribe/{plan}', [SubscribeController::class, 'start'])
        ->where('plan', 'starter|growth|operator')
        ->middleware('throttle:10,1')
        ->name('subscribe.start');

    // Switching an EXISTING subscription between rungs or billing cycles.
    // Separate from start() on purpose — running an already-subscribed team
    // through Checkout again creates a second subscription and double-bills.
    Route::post('/subscribe/change/{plan}', [SubscribeController::class, 'change'])
        ->where('plan', 'starter|growth|operator')
        ->middleware('throttle:10,1')
        ->name('subscribe.change');
    Route::post('/subscribe/schedule-cancel', [SubscribeController::class, 'scheduleCancel'])
        ->middleware('throttle:10,1')
        ->name('subscribe.schedule-cancel');
    Route::post('/subscribe/resume', [SubscribeController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('subscribe.resume');

    Route::get('/subscribe/success', [SubscribeController::class, 'success'])
        ->name('subscribe.success');
    Route::get('/subscribe/cancel', [SubscribeController::class, 'cancel'])
        ->name('subscribe.cancel');

    Route::post('/billing/topup', [BillingController::class, 'topup'])
        ->middleware('throttle:10,1')
        ->name('billing.topup');

    // Customer Portal — Stripe-hosted page for cancel, update card, view
    // invoices. Requires the team to have a stripe_customer_id (so at least
    // one subscribe or topup has happened). Cancellations come back via
    // the customer.subscription.deleted webhook.
    Route::post('/billing/portal', [BillingController::class, 'portal'])
        ->middleware('throttle:10,1')
        ->name('billing.portal');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Phase 3 lead pipeline (kanban board with live updates).
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    // Registered before /leads/{lead} so "board" isn't bound as a {lead}.
    Route::get('/leads/board', [LeadController::class, 'board'])->name('leads.board');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('leads.store');
    // leads.update intentionally removed — there's no edit-lead UI; the
    // kanban uses leads.status for status-only changes and the create modal
    // covers the only field-editing surface. Re-add when a per-lead edit
    // page actually exists.
    Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])
        ->middleware('throttle:120,1')
        ->name('leads.status');
    Route::patch('/leads/{lead}/contacted', [LeadController::class, 'markContacted'])
        ->middleware('throttle:60,1')
        ->name('leads.contacted');
    Route::patch('/leads/{lead}/notes', [LeadController::class, 'updateNotes'])
        ->middleware('throttle:120,1')
        ->name('leads.notes');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])
        ->middleware('throttle:30,1')
        ->name('leads.assign');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('leads.destroy');

    // Chat panel (server-proxied native runtime).
    // Named `chat.*` (not `agent.*`) to avoid collision with the `agents.*`
    // CRUD routes — the one-letter difference between `agent.index` and
    // `agents.index` was a constant footgun.
    Route::get('/chat', fn () => Inertia::render('Chat/Index', [
        'configured' => (bool) request()->user()?->currentTeam?->currentAgent?->isConfigured(),
    ]))->name('chat.index');
    Route::post('/chat/launch', [ChatController::class, 'launch'])
        ->middleware('throttle:30,1')
        ->name('chat.launch');
    Route::post('/chat/interact', [ChatController::class, 'interact'])
        ->middleware('throttle:60,1')
        ->name('chat.interact');
    Route::post('/chat/interact/stream', [ChatController::class, 'interactStream'])
        ->middleware('throttle:60,1')
        ->name('chat.interact-stream');
    Route::get('/chat/health', [ChatController::class, 'health'])->name('chat.health');

    // Phase 6 conversation storage, history & search.
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])
        ->name('conversations.messages');
    // Human takeover: reply to the visitor live from the dashboard (first
    // reply pauses the AI), release hands the conversation back to it.
    Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])
        ->middleware('throttle:60,1')
        ->name('conversations.reply');
    Route::post('/conversations/{conversation}/release', [ConversationController::class, 'release'])
        ->middleware('throttle:30,1')
        ->name('conversations.release');
    Route::post('/conversations/{conversation}/end-upstream', [ConversationController::class, 'endUpstream'])
        ->middleware('throttle:30,1')
        ->name('conversations.end-upstream');
    Route::delete('/conversations/{conversation}/upstream', [ConversationController::class, 'deleteUpstream'])
        ->middleware('throttle:10,1')
        ->name('conversations.delete-upstream');

    // Notifications (bell): mark all read.
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])
        ->middleware('throttle:60,1')
        ->name('notifications.read');

    // "Install on your website" — embed snippet + instructions for the
    // current team's current agent. Sidebar nav reaches it; onboarding's
    // Done page links to it as the final activation step.
    Route::get('/install', [InstallController::class, 'index'])
        ->name('install.index');
    Route::put('/install/widget', [InstallController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('install.update');

    // The Suite — every module in two lines (the app, the Studio). A
    // `coming` module's only action is "request it", recorded per team.
    Route::get('/suite', [SuiteController::class, 'index'])->name('suite.index');
    Route::post('/suite/request', [SuiteController::class, 'request'])
        ->middleware('throttle:30,1')
        ->name('suite.request');

    // Knowledge Base (native runtime RAG store).
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
    Route::post('/knowledge/url', [KnowledgeBaseController::class, 'storeUrl'])
        ->middleware('throttle:30,1')
        ->name('knowledge.url');
    Route::post('/knowledge/file', [KnowledgeBaseController::class, 'storeFile'])
        ->middleware('throttle:20,1')
        ->name('knowledge.file');
    Route::post('/knowledge/text', [KnowledgeBaseController::class, 'storeText'])
        ->middleware('throttle:30,1')
        ->name('knowledge.text');
    Route::post('/knowledge/query', [KnowledgeBaseController::class, 'query'])
        ->middleware('throttle:60,1')
        ->name('knowledge.query');
    // Registered before /knowledge/{documentID} so "gaps" isn't bound as a document id.
    Route::delete('/knowledge/gaps/{gap}', [KnowledgeBaseController::class, 'resolveGap'])
        ->middleware('throttle:30,1')
        ->name('knowledge.gaps.resolve');
    Route::post('/knowledge/gaps/{gap}/answer', [KnowledgeBaseController::class, 'resolveGapWithAnswer'])
        ->middleware('throttle:30,1')
        ->name('knowledge.gaps.answer');
    Route::get('/knowledge/{documentID}', [KnowledgeBaseController::class, 'show'])
        ->where('documentID', '[A-Za-z0-9_\-]+')
        ->name('knowledge.show');
    Route::delete('/knowledge/{documentID}', [KnowledgeBaseController::class, 'destroy'])
        ->where('documentID', '[A-Za-z0-9_\-]+')
        ->middleware('throttle:30,1')
        ->name('knowledge.destroy');
});

// Stripe webhook — public, no auth, no CSRF, signature-verified inside the
// controller. Lives outside the auth group because Stripe doesn't send
// session cookies or a CSRF token; it signs the body with the webhook secret.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->name('webhooks.stripe');

// Public chat embed — runs on the customer's website via a <script> tag.
// All four endpoints are unauthenticated; per-agent-slug authorization
// is inside the controller (refuses non-active agents). Throttled per IP.
Route::get('/widget/{slug}.js', [EmbedController::class, 'widget'])
    ->middleware('throttle:120,1')
    ->name('embed.widget');
Route::get('/embed/{slug}', [EmbedController::class, 'chat'])
    ->middleware('throttle:120,1')
    ->name('embed.chat');
Route::post('/embed/{slug}/launch', [EmbedController::class, 'launch'])
    ->middleware('throttle:60,1')
    ->name('embed.launch');
Route::post('/embed/{slug}/interact', [EmbedController::class, 'interact'])
    ->middleware('throttle:120,1')
    ->name('embed.interact');
Route::post('/embed/{slug}/feedback', [EmbedController::class, 'feedback'])
    ->middleware('throttle:30,1')
    ->name('embed.feedback');
Route::post('/embed/{slug}/history', [EmbedController::class, 'history'])
    ->middleware('throttle:120,1')
    ->name('embed.history');
// Human-takeover poll: the widget checks for team-member replies every few
// seconds while a handoff is active (4s cadence ≈ 15/min — well inside).
Route::post('/embed/{slug}/poll', [EmbedController::class, 'poll'])
    ->middleware('throttle:60,1')
    ->name('embed.poll');
Route::post('/embed/{slug}/conversation', [EmbedController::class, 'transcript'])
    ->middleware('throttle:120,1')
    ->name('embed.conversation');

// ── Local-only operator page: Hermes effectiveness KPIs ───────────────────────
// Git-mined quality trend + regression check (composer hermes-metrics). Registered
// ONLY in local so it never reaches production or the route-smoke test; behind
// auth but NOT RequireAgent (it's an ops tool, works with no agent).
if (app()->environment('local')) {
    Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
        ->get('/hermes-metrics', HermesMetricsController::class)
        ->name('hermes.metrics');

    // Live render of docs/architecture/full-application-graph.md as Mermaid
    // diagrams — same local-only, auth-but-no-agent gating as hermes-metrics.
    Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
        ->get('/architecture', ArchitectureGraphController::class)
        ->name('architecture.graph');
}
