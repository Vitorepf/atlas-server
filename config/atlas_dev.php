<?php

/*
|--------------------------------------------------------------------------
| Atlas Dev Efficient Programming Flow
|--------------------------------------------------------------------------
|
| Feature flags + persistence knobs for the Atlas Dev Efficient pipeline.
| The flags are layered so the operator can ship Atlas Dev in stages:
|
|   1. master flag                       (`enabled`)
|   2. plan-only surface                 (`plan_enabled`)
|   3. provider run surface              (`run_enabled`)
|   4. desktop integration surface       (`desktop_enabled`)
|
| Defaults are conservative on purpose: plan-only is on in dev/local because
| it is read-only and has no provider cost; run is OFF unless explicitly
| opted-in via env (`ATLAS_DEV_EFFICIENT_RUN_ENABLED=true`); desktop is OFF
| until the UX is signed off.
|
| Canon: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
*/

return [
    'efficient' => [
        'enabled' => (bool) env(
            'ATLAS_DEV_EFFICIENT_ENABLED',
            in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true),
        ),

        'plan_enabled' => (bool) env(
            'ATLAS_DEV_EFFICIENT_PLAN_ENABLED',
            in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true),
        ),

        'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', false),

        'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false),
    ],

    'confirmation_token' => [
        'ttl_seconds' => (int) env('ATLAS_DEV_CONFIRMATION_TOKEN_TTL_SECONDS', 300),
        'plaintext_bytes' => (int) env('ATLAS_DEV_CONFIRMATION_TOKEN_BYTES', 32),
    ],

    'run_index' => [
        'list_default_limit' => (int) env('ATLAS_DEV_RUN_INDEX_LIST_LIMIT', 50),
        'list_max_limit' => (int) env('ATLAS_DEV_RUN_INDEX_LIST_MAX_LIMIT', 200),
    ],

    'receipts_path' => env(
        'ATLAS_DEV_RECEIPTS_PATH',
        function_exists('storage_path') ? storage_path('atlas-dev/receipts') : sys_get_temp_dir().'/atlas-dev/receipts',
    ),

    // F-01: under PHP-FPM the SSE endpoint cannot keep a worker pinned, so the
    // current StreamController is snapshot-replay-then-close and does not read
    // these knobs. They are reserved for a future async runtime (Octane / Reverb
    // / ReactPHP) where a single process can host many idle long-lived streams.
    'stream' => [
        'timeout_seconds' => (int) env('ATLAS_DEV_STREAM_TIMEOUT_SECONDS', 300),
        'keepalive_seconds' => (int) env('ATLAS_DEV_STREAM_KEEPALIVE_SECONDS', 15),
    ],
];
