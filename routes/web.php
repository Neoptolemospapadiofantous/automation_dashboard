<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardTickController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscribeController;
use App\Http\Controllers\Voiceflow\EnvironmentsController;
use App\Http\Controllers\Voiceflow\EvaluationsController;
use App\Http\Controllers\VoiceflowController;
use App\Http\Middleware\RequireAgent;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    RequireAgent::class,
])->group(function () {
    // Phase 13 — Agent CRUD + onboarding wizard. Both bypass RequireAgent
    // (the middleware whitelists their route name prefixes) so the user can
    // reach them even when no active agent exists yet.
    //
    // Phase 14: BYOK was removed from the product surface — the wizard is
    // now a single managed-only flow (intro → start → done). The Connect
    // step (paste keys / saveCredentials) is gone. The controller still
    // ships the credential-validation rules + UpdateAgentCredentials action
    // because ops uses them via tinker for one-off Custom-tier BYOK setups.
    Route::get('/onboarding', [OnboardingController::class, 'intro'])->name('onboarding.intro');
    Route::post('/onboarding/start', [OnboardingController::class, 'startAgent'])
        ->middleware('throttle:5,1')
        ->name('onboarding.start');
    Route::get('/onboarding/done', [OnboardingController::class, 'done'])->name('onboarding.done');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('agents.store');

    // Specific /agents/{evaluations,environments} routes MUST come before the
    // wildcard /agents/{agent} below — Laravel matches by registration order
    // and the wildcard's slug binding would otherwise swallow these and 404
    // (no agent has slug 'evaluations' or 'environments').
    Route::get('/agents/evaluations', [EvaluationsController::class, 'index'])
        ->name('agents.evaluations.index');
    Route::post('/agents/evaluations', [EvaluationsController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('agents.evaluations.store');
    Route::get('/agents/evaluations/{evaluationId}', [EvaluationsController::class, 'show'])
        ->where('evaluationId', '[A-Za-z0-9_-]+')
        ->name('agents.evaluations.show');
    Route::post('/agents/evaluations/{evaluationId}/run', [EvaluationsController::class, 'run'])
        ->where('evaluationId', '[A-Za-z0-9_-]+')
        ->middleware('throttle:30,1')
        ->name('agents.evaluations.run');
    Route::delete('/agents/evaluations/{evaluationId}', [EvaluationsController::class, 'destroy'])
        ->where('evaluationId', '[A-Za-z0-9_-]+')
        ->middleware('throttle:30,1')
        ->name('agents.evaluations.destroy');

    Route::get('/agents/environments', [EnvironmentsController::class, 'index'])
        ->name('agents.environments.index');
    Route::post('/agents/environments/clone', [EnvironmentsController::class, 'clone'])
        ->middleware('throttle:10,1')
        ->name('agents.environments.clone');
    Route::post('/agents/environments/{idOrAlias}/publish', [EnvironmentsController::class, 'publish'])
        ->where('idOrAlias', '[A-Za-z0-9_-]+')
        ->middleware('throttle:10,1')
        ->name('agents.environments.publish');
    Route::delete('/agents/environments/{environmentId}', [EnvironmentsController::class, 'destroy'])
        ->where('environmentId', '[A-Za-z0-9_-]+')
        ->middleware('throttle:10,1')
        ->name('agents.environments.destroy');
    Route::get('/agents/environments/{alias}/export', [EnvironmentsController::class, 'export'])
        ->where('alias', '[A-Za-z0-9_-]+')
        ->name('agents.environments.export');
    Route::get('/agents/environments/traffic.json', [EnvironmentsController::class, 'traffic'])
        ->name('agents.environments.traffic');

    Route::get('/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::put('/agents/{agent}', [AgentController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('agents.update');
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('agents.destroy');
    Route::post('/agents/{agent}/rotate-secret', [AgentController::class, 'rotateSecret'])
        ->middleware('throttle:5,1')
        ->name('agents.rotate-secret');
    Route::post('/agents/{agent}/health', [AgentController::class, 'health'])
        ->middleware('throttle:30,1')
        ->name('agents.health');
    Route::put('/current-agent', [AgentController::class, 'switchCurrent'])
        ->middleware('throttle:60,1')
        ->name('current-agent.update');

    // Billing — current plan, credit history, top-up purchase.
    // Top-up flow is DEV-MODE (instant grant) until Phase H wires Stripe
    // Checkout. See BillingController::topup for the swap-over plan.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');

    // Stripe subscription checkout. Plan key is one of 'starter' | 'operator'.
    Route::post('/subscribe/{plan}', [SubscribeController::class, 'start'])
        ->where('plan', 'starter|operator')
        ->middleware('throttle:10,1')
        ->name('subscribe.start');
    Route::get('/subscribe/success', [SubscribeController::class, 'success'])
        ->name('subscribe.success');
    Route::get('/subscribe/cancel', [SubscribeController::class, 'cancel'])
        ->name('subscribe.cancel');

    Route::post('/billing/topup', [BillingController::class, 'topup'])
        ->middleware('throttle:10,1')
        ->name('billing.topup');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Phase 2 live-tick demo: fires a broadcast every connected browser receives.
    Route::post('/dashboard/tick', DashboardTickController::class)
        ->middleware('throttle:30,1')
        ->name('dashboard.tick');

    // Phase 3 lead pipeline (kanban board with live updates).
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
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
    Route::patch('/leads/{lead}/notes', [LeadController::class, 'updateNotes'])
        ->middleware('throttle:120,1')
        ->name('leads.notes');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])
        ->middleware('throttle:30,1')
        ->name('leads.assign');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('leads.destroy');

    // Phase 5 chat panel (server-proxied Voiceflow Dialog Manager API).
    // Multi-tenant: `configured` reflects THIS user's current agent, not the
    // app-wide .env fallback. A SaaS user whose own agent is healthy sees the
    // chat panel even if the deployment has no global VOICEFLOW_API_KEY.
    //
    // Named `chat.*` (not `agent.*`) to avoid collision with the `agents.*`
    // CRUD routes — the one-letter difference between `agent.index` and
    // `agents.index` was a constant footgun.
    Route::get('/chat', fn () => Inertia::render('Chat/Index', [
        'configured' => (bool) request()->user()?->currentTeam?->currentAgent?->isConfigured(),
    ]))->name('chat.index');
    Route::post('/chat/launch', [VoiceflowController::class, 'launch'])
        ->middleware('throttle:30,1')
        ->name('chat.launch');
    Route::post('/chat/interact', [VoiceflowController::class, 'interact'])
        ->middleware('throttle:60,1')
        ->name('chat.interact');
    Route::post('/chat/interact/stream', [VoiceflowController::class, 'interactStream'])
        ->middleware('throttle:60,1')
        ->name('chat.interact-stream');
    Route::get('/chat/health', [VoiceflowController::class, 'health'])->name('chat.health');

    // Phase 6 conversation storage, history & search.
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/search', [ConversationController::class, 'search'])->name('conversations.search');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
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

    // Phase 12 Knowledge Base (Voiceflow KB API).
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
