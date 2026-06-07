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

    'openstreet' => [
        'url' => env('OPENSTREET_API_URL', 'https://maps.open-street.com/api/tsp/'),
        'key' => env('OPENSTREET_API_KEY'),
        // Read timeout: the API can take several minutes for large point sets,
        // so this is intentionally generous. The async job pattern means the
        // user is never blocked on it.
        'timeout' => (int) env('OPENSTREET_API_TIMEOUT', 600),
        // Connection timeout: fail fast if the host is unreachable (dead DNS /
        // refused connection) so a worker never hangs forever.
        'connect_timeout' => (int) env('OPENSTREET_API_CONNECT_TIMEOUT', 15),
        'retries' => (int) env('OPENSTREET_API_RETRIES', 1),
        // Hard ceiling for the whole queue job. Must cover EVERY upstream attempt
        // — (retries + 1) calls × read timeout + backoff — so a retry is never cut
        // short, and must stay below the queue connection's retry_after.
        // Default: (1 + 1) × 600 + 60 buffer = 1260.
        'job_timeout' => (int) env('OPENSTREET_API_JOB_TIMEOUT', 1260),
    ],

];
