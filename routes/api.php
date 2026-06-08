<?php

use App\Http\Controllers\PublicStatsController;
use App\Http\Controllers\Voiceflow\OrgEventsController;
use App\Http\Controllers\Voiceflow\SessionLifecycleController;
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
Route::middleware('throttle:60,1')
    ->post('/voiceflow/lead-captured/{agent:slug}', [VoiceflowWebhookController::class, 'leadCaptured'])
    ->name('voiceflow.webhook');

// Voiceflow inbound session-lifecycle events (runtime.session.*, runtime.call.*).
// Auth is the same per-agent X-Webhook-Secret used for the Custom Action above.
// Persists every event to voiceflow_webhook_events for idempotency + audit.
Route::middleware('throttle:120,1')
    ->post('/voiceflow/webhooks/session/{agent:slug}', SessionLifecycleController::class)
    ->name('voiceflow.webhook.session');

// Voiceflow inbound org-events (organization.project.*). Platform-level secret
// (X-Voiceflow-Org-Secret). Reactively retires voiceflow_project_pool entries
// when a project is deleted upstream so PoolAllocator doesn't hand out a
// project that no longer exists.
Route::middleware('throttle:60,1')
    ->post('/voiceflow/webhooks/org', OrgEventsController::class)
    ->name('voiceflow.webhook.org');

// Public, unauthenticated platform stats for the marketing site
// (founder-slots-remaining + aggregate counts). Throttled per IP — the
// response is cached server-side so a scraper hits the cache, not the DB.
// CORS headers set inline by the controller; no global cors.php needed.
Route::middleware('throttle:60,1')
    ->get('/public/stats', [PublicStatsController::class, 'show'])
    ->name('public.stats');
