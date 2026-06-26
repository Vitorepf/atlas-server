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

    'rize' => [
        'api_key' => env('RIZE_API_KEY'),
        'webhook_secret' => env('RIZE_WEBHOOK_SECRET'),
        'graphql_endpoint' => env('RIZE_GRAPHQL_ENDPOINT', 'https://api.rize.io/api/v1/graphql'),
        'timezone' => env('RIZE_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
        'http_timeout' => (int) env('RIZE_HTTP_TIMEOUT', 30),
        'sync_enabled' => filter_var(env('RIZE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'sync_lookback_days' => (int) env('RIZE_SYNC_LOOKBACK_DAYS', 2),
        'sync_page_size' => (int) env('RIZE_SYNC_PAGE_SIZE', 100),
        'sessions_query' => env('RIZE_SESSIONS_QUERY'),
        'sessions_query_path' => env('RIZE_SESSIONS_QUERY_PATH'),
        'sessions_root_path' => env('RIZE_SESSIONS_ROOT_PATH', 'timeEntries.nodes'),
        'sessions_page_info_path' => env('RIZE_SESSIONS_PAGE_INFO_PATH', 'timeEntries.pageInfo'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        'enabled' => env('SENTRY_LARAVEL_DSN') !== null && env('SENTRY_LARAVEL_DSN') !== '',
        'release' => env('SENTRY_RELEASE', env('APP_VERSION', 'unknown')),
        'environment' => env('APP_ENV', 'production'),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
        'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.01),
    ],

    'opentelemetry' => [
        'enabled' => filter_var(env('OTEL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'service_name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'Atlas')),
        'service_version' => env('OTEL_SERVICE_VERSION', env('APP_VERSION', 'unknown')),
    ],

];
