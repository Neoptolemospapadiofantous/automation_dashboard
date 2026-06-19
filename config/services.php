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
        // Incoming-Webhook URL used by SlackWebhook (hermes:alert + slack:digest).
        // Fully local: a plain POST to this URL, no bot token / Web API. Unset → silent.
        'webhook_url' => env('SLACK_ALERT_WEBHOOK_URL'),

        // Two-way bot (slack:listen daemon, Socket Mode — fully local, no public
        // inbound endpoint). bot_token (xoxb-) drives Web API actions; app_token
        // (xapp-, connections:write) opens the WebSocket. Both unset → daemon refuses.
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'app_token' => env('SLACK_APP_TOKEN'),

        // Comma-separated Slack user IDs allowed to run credit-spending (/agent ask)
        // and channel-admin commands. Everyone else can still be answered on
        // @mention/DM, but cannot trigger admin actions or spend.
        'admin_users' => array_filter(array_map('trim', explode(',', (string) env('SLACK_ADMIN_USERS', '')))),

        // Which local Team owns billing for Slack-originated LLM turns, and
        // (optionally) which Agent answers. Blank agent → the team's active agent.
        'team_id' => env('SLACK_TEAM_ID'),
        'agent_id' => env('SLACK_AGENT_ID'),

        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
