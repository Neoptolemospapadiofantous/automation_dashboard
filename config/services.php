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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'voiceflow' => [
        // Dialog Manager API key (prefix VF.DM.*). Kept server-side only.
        // In BYOK mode this is a fallback for cron/CLI; in managed mode
        // it's the master DM key for the workspace where every tenant
        // environment lives.
        'api_key' => env('VOICEFLOW_API_KEY'),

        // Managed mode (Phase J): we own one master Voiceflow project +
        // a template environment. On signup, CreateAgent clones the
        // template into a fresh per-tenant environment via the Project
        // API. Users never paste keys.
        //
        // Phase K: managed-mode signup allocates a pre-created Voiceflow
        // project from voiceflow_project_pool (operator-managed). Top up
        // with `php artisan vf:pool:add`. The earlier env-clone variables
        // (master_project_id, template_environment_id) were removed when
        // Voiceflow docs confirmed a 10-env-per-project cap + shared KB
        // across environments — see docs/phase-13-multitenancy.md.
        'managed' => [
            'enabled' => env('VOICEFLOW_MANAGED', false),
        ],
        // Optional workspace-scoped key. Used by analytics/transcripts (host
        // analytics-api.voiceflow.com) and KB CRUD/query. Falls back to
        // the DM key when unset — most tenants need to set this explicitly.
        'workspace_api_key' => env('VOICEFLOW_WORKSPACE_API_KEY'),
        // V4 uses environments (alias "main") instead of legacy version aliases.
        'environment' => env('VOICEFLOW_ENVIRONMENT', 'main'),
        // Required for the V4 start-session endpoint. Found in agent settings.
        'project_id' => env('VOICEFLOW_PROJECT_ID'),
        'runtime_url' => env('VOICEFLOW_RUNTIME_URL', 'https://general-runtime.voiceflow.com'),
        'api_url' => env('VOICEFLOW_API_URL', 'https://api.voiceflow.com'),
        // Analytics/Transcript API lives on a separate host.
        'analytics_url' => env('VOICEFLOW_ANALYTICS_URL', 'https://analytics-api.voiceflow.com'),
        // Knowledge Base document management lives on the realtime host.
        'realtime_url' => env('VOICEFLOW_REALTIME_URL', 'https://realtime-api.voiceflow.com'),
        // Lead variables to read out of the agent's session after each turn.
        'lead_variables' => ['name', 'email', 'phone', 'company'],
        // Webhook shared secret for the Voiceflow Custom Action capture endpoint.
        'webhook_secret' => env('VOICEFLOW_WEBHOOK_SECRET'),
    ],

];
