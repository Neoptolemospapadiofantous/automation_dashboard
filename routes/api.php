<?php

use App\Http\Controllers\VoiceflowWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Inbound Voiceflow Custom Action webhook (secured by a shared secret header).
Route::post('/voiceflow/lead-captured', [VoiceflowWebhookController::class, 'leadCaptured'])
    ->name('voiceflow.webhook');
