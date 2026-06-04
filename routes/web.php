<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardTickController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\VoiceflowController;
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
    \App\Http\Middleware\RequireAgent::class,
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
    Route::post('/onboarding/start', [OnboardingController::class, 'startAgent'])->name('onboarding.start');
    Route::get('/onboarding/done', [OnboardingController::class, 'done'])->name('onboarding.done');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
    Route::get('/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::put('/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
    Route::post('/agents/{agent}/rotate-secret', [AgentController::class, 'rotateSecret'])->name('agents.rotate-secret');
    Route::post('/agents/{agent}/health', [AgentController::class, 'health'])->name('agents.health');
    Route::put('/current-agent', [AgentController::class, 'switchCurrent'])->name('current-agent.update');

    // Billing — current plan, credit history, top-up purchase.
    // Top-up flow is DEV-MODE (instant grant) until Phase H wires Stripe
    // Checkout. See BillingController::topup for the swap-over plan.
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/topup', [\App\Http\Controllers\BillingController::class, 'topup'])->name('billing.topup');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Phase 2 live-tick demo: fires a broadcast every connected browser receives.
    Route::post('/dashboard/tick', DashboardTickController::class)->name('dashboard.tick');

    // Phase 3 lead pipeline (kanban board with live updates).
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    // leads.update intentionally removed — there's no edit-lead UI; the
    // kanban uses leads.status for status-only changes and the create modal
    // covers the only field-editing surface. Re-add when a per-lead edit
    // page actually exists.
    Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.status');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

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
    Route::post('/chat/launch', [VoiceflowController::class, 'launch'])->name('chat.launch');
    Route::post('/chat/interact', [VoiceflowController::class, 'interact'])->name('chat.interact');
    Route::get('/chat/health', [VoiceflowController::class, 'health'])->name('chat.health');

    // Phase 6 conversation storage, history & search.
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/search', [ConversationController::class, 'search'])->name('conversations.search');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');

    // Notifications (bell): mark all read.
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');

    // Phase 12 Knowledge Base (Voiceflow KB API).
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
    Route::post('/knowledge/url', [KnowledgeBaseController::class, 'storeUrl'])->name('knowledge.url');
    Route::post('/knowledge/file', [KnowledgeBaseController::class, 'storeFile'])->name('knowledge.file');
    Route::post('/knowledge/query', [KnowledgeBaseController::class, 'query'])->name('knowledge.query');
    Route::get('/knowledge/{documentID}', [KnowledgeBaseController::class, 'show'])
        ->where('documentID', '[A-Za-z0-9_\-]+')
        ->name('knowledge.show');
    Route::delete('/knowledge/{documentID}', [KnowledgeBaseController::class, 'destroy'])
        ->where('documentID', '[A-Za-z0-9_\-]+')
        ->name('knowledge.destroy');
});
