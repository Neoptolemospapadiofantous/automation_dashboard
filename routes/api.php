<?php

use App\Http\Controllers\PublicStatsController;
use App\Http\Controllers\VoiceflowWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Inbound Voiceflow Custom Action webhook (secured by a per-agent secret).
// The {agent:slug} segment route-binds to the Agent model; its team_id and
// webhook_secret are the source of truth for tenancy + auth — the Voiceflow
// side just POSTs to a tenant-specific URL with the matching secret.
Route::post('/voiceflow/lead-captured/{agent:slug}', [VoiceflowWebhookController::class, 'leadCaptured'])
    ->name('voiceflow.webhook');

// Public, unauthenticated platform stats for the marketing site
// (founder-slots-remaining + aggregate counts). Throttled per IP — the
// response is cached server-side so a scraper hits the cache, not the DB.
// CORS headers set inline by the controller; no global cors.php needed.
Route::middleware('throttle:60,1')
    ->get('/public/stats', [PublicStatsController::class, 'show'])
    ->name('public.stats');
