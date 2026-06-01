<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardTickController;
use App\Http\Controllers\LeadController;
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
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // Phase 2 live-tick demo: fires a broadcast every connected browser receives.
    Route::post('/dashboard/tick', DashboardTickController::class)->name('dashboard.tick');

    // Phase 3 lead pipeline (kanban board with live updates).
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.status');
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
});
