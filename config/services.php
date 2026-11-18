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

    'ai' => [
        // Database ai_provider_credentials adalah satu-satunya sumber API Key runtime (tanpa fallback .env).
        'base_url' => env('AI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        'model' => env('AI_MODEL', 'gemini-2.5-flash'),
        'temperature' => (float) env('AI_TEMPERATURE', 0.2),
        'timeout' => (int) env('AI_REQUEST_TIMEOUT', 15),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 300),
        'allow_local_ollama' => (bool) env('AI_ALLOW_LOCAL_OLLAMA', false),
    ],

    'fonnte' => [
        'token' => env('FONNTE_API_TOKEN'),
        'enabled' => (bool) env('FONNTE_ENABLED', false),
        'country_code' => env('FONNTE_DEFAULT_COUNTRY_CODE', '62'),
        'timeout' => (int) env('FONNTE_TIMEOUT', 15),
        'url' => env('FONNTE_API_URL', 'https://api.fonnte.com/send'),
        'device_url' => env('FONNTE_DEVICE_URL', 'https://api.fonnte.com/device'),
        'use_queue' => (bool) env('FONNTE_QUEUE_ENABLED', true),
        'queues' => [
            'sktm' => env('FONNTE_QUEUE_SKTM', 'sktm'),
            'report_priority' => env('FONNTE_QUEUE_REPORT_PRIORITY', 'laporan-prioritas'),
            'report' => env('FONNTE_QUEUE_REPORT', 'laporan'),
        ],
        'default_recipient' => env('FONNTE_DEFAULT_RECIPIENT_PHONE'),
        'admin_phones' => [
            'staf' => env('FONNTE_STAFF_PHONE'),
            'supervisor' => env('FONNTE_SUPERVISOR_PHONE'),
            'kades' => env('FONNTE_KADES_PHONE'),
            'kepala_desa' => env('FONNTE_KADES_PHONE'),
        ],
    ],

];
