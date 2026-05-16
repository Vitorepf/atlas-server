<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS · Atlas Code MVP
    |--------------------------------------------------------------------------
    |
    | Allow the atlas-desktop dev server (Vite on :5173) to consume the
    | atlas-server endpoints during local development. Production hosts must
    | be added explicitly.
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'tauri://localhost',
        'http://tauri.localhost',
    ],

    'allowed_origins_patterns' => [
        '#^http://localhost:51[0-9]{2}$#',
        '#^http://127\.0\.0\.1:51[0-9]{2}$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
