<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Escalation VOICE CALL (App\Notifications\Channels\CallMeBotTelegramCallChannel):
    // CallMeBot's free Telegram Call API rings the founder's phone and a
    // TTS voice reads the alert. telegram_user = @username or the phone
    // (+357…) the founder's Telegram is registered with; they must have
    // authorized @CallMeBot_txtbot (/start) once. Unset = channel inert.
    'callmebot' => [
        'telegram_user' => env('CALLMEBOT_TELEGRAM_USER', ''),
    ],

    // Ecosystem BI warehouse ingestion API (SHARED.md — ~/.config/ecosystem/bi).
    // Best-effort live telemetry, OFF by default. The API is localhost-only, so
    // prod stays dormant until a reachable endpoint (Azure) exists — enabling is
    // then just the env flag, no deploy of code. Consumed by App\Support\BiEmitter.
    'bi' => [
        'enabled' => (bool) env('BI_EMITTER_ENABLED', false),
        'url' => env('BI_INGEST_URL'),
        'token' => env('BI_INGEST_TOKEN'),
    ],

];
