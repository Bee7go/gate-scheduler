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

    'opensky' => [
        'verify_ssl' => env('OPENSKY_VERIFY_SSL', true),
        'airport_icao' => env('AIRPORT_ICAO'),
        'lookback_seconds' => env('OPENSKY_LOOKBACK_SECONDS'),
        'fetch_max_attempts' => env('OPENSKY_FETCH_MAX_ATTEMPTS', 3),
        'fetch_retry_base_delay_ms' => env('OPENSKY_FETCH_RETRY_BASE_DELAY_MS', 500),
        'fetch_timeout_seconds' => env('OPENSKY_FETCH_TIMEOUT_SECONDS', 10),
        'fallback_cache_ttl_seconds' => env('OPENSKY_FALLBACK_CACHE_TTL_SECONDS', 900),
        'breaker_failure_threshold' => env('OPENSKY_BREAKER_FAILURE_THRESHOLD', 3),
        'breaker_failure_window_seconds' => env('OPENSKY_BREAKER_FAILURE_WINDOW_SECONDS', 300),
        'breaker_cooldown_seconds' => env('OPENSKY_BREAKER_COOLDOWN_SECONDS', 600),
        'token_url' => env('OPENSKY_TOKEN_URL'),
        'client_id' => env('OPENSKY_CLIENT_ID'),
        'client_secret' => env('OPENSKY_CLIENT_SECRET'),
    ],

    'gates' => [
        'occupation_minutes' => env('GATE_OCCUPATION_TIME'),
        'allocation_strategy' => env('GATE_ALLOCATION_STRATEGY'),
    ],

];
