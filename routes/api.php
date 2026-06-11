<?php

use App\Http\Controllers\PublicStatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public, unauthenticated platform stats for the marketing site
// (founder-slots-remaining + aggregate counts). Throttled per IP — the
// response is cached server-side so a scraper hits the cache, not the DB.
// CORS headers set inline by the controller; no global cors.php needed.
Route::middleware('throttle:60,1')
    ->get('/public/stats', [PublicStatsController::class, 'show'])
    ->name('public.stats');
