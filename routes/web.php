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
    Route::get('/onboarding', [OnboardingController::class, 'intro'])->name('onboarding.intro');
    Route::post('/onboarding/start', [OnboardingController::class, 'startAgent'])->name('onboarding.start');
    Route::get('/onboarding/connect', [OnboardingController::class, 'connect'])->name('onboarding.connect');
    Route::post('/onboarding/connect', [OnboardingController::class, 'saveCredentials'])->name('onboarding.save');
    Route::get('/onboarding/done', [OnboardingController::class, 'done'])->name('onboarding.done');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
    Route::get('/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::put('/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
    Route::post('/agents/{agent}/rotate-secret', [AgentController::class, 'rotateSecret'])->name('agents.rotate-secret');
    Route::post('/agents/{agent}/health', [AgentController::class, 'health'])->name('agents.health');
    Route::put('/current-agent', [AgentController::class, 'switchCurrent'])->name('current-agent.update');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Phase 2 live-tick demo: fires a broadcast every connected browser receives.
    Route::post('/dashboard/tick', DashboardTickController::class)->name('dashboard.tick');

    // Phase 3 lead pipeline (kanban board with live updates).
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.status');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    // Phase 5 Voiceflow agent (server-proxied Dialog Manager API).
    Route::get('/agent', fn () => Inertia::render('Agent/Index', [
        'configured' => ! empty(config('services.voiceflow.api_key')) && ! empty(config('services.voiceflow.project_id')),
    ]))->name('agent.index');
    Route::post('/agent/launch', [VoiceflowController::class, 'launch'])->name('agent.launch');
    Route::post('/agent/interact', [VoiceflowController::class, 'interact'])->name('agent.interact');
    Route::get('/agent/health', [VoiceflowController::class, 'health'])->name('agent.health');

    // Phase 6 conversation storage, history & search.
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/search', [ConversationController::class, 'search'])->name('conversations.search');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');

    // Notifications (bell): mark all read.
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');

    // Phase 12 Knowledge Base (Voiceflow KB API).
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
    Route::post('/knowledge/url', [KnowledgeBaseController::class, 'storeUrl'])->name('knowledge.url');
    Route::post('/knowledge/query', [KnowledgeBaseController::class, 'query'])->name('knowledge.query');
});
