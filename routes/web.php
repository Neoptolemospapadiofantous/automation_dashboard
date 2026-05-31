<?php

use App\Http\Controllers\DashboardTickController;
use App\Http\Controllers\LeadController;
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
});
