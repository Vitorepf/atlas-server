<?php

use Illuminate\Http\Request;

return [
    'title' => 'Atlas Server API',
    'description' => 'Personal life-capture and Atlas AI Gateway API.',
    'routes' => [
        [
            'match' => [
                'prefixes' => ['*'],
                'versions' => ['v1'],
            ],
            'include' => [],
            'exclude' => [],
            'apply' => [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer {ATLAS_TOKEN}',
                ],
                'response_calls' => [
                    'methods' => ['GET'],
                    'config' => ['app.env' => 'testing'],
                    'queryParams' => [],
                    'bodyParams' => [],
                    'fileParams' => [],
                    'cookies' => [],
                ],
            ],
        ],
    ],
    'type' => 'laravel',
    'static' => [
        'output_path' => 'public/docs',
    ],
    'laravel' => [
        'add_routes' => true,
        'docs_url' => '/docs',
        'middleware' => [],
    ],
    'try_it_out' => [
        'enabled' => false,
        'base_url' => env('APP_URL', 'http://localhost:3737'),
    ],
    'auth' => [
        'enabled' => true,
        'default' => true,
        'in' => 'bearer',
        'name' => 'ATLAS_TOKEN',
        'use_value' => env('ATLAS_TOKEN'),
        'placeholder' => '{ATLAS_TOKEN}',
        'extra_info' => 'The operator token configured in .env as ATLAS_TOKEN.',
    ],
    'intro_text' => 'Atlas Server API documentation.',
    'example_languages' => ['bash', 'php', 'python'],
    'postman' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => env('APP_VERSION', '1.0.0'),
        ],
    ],
    'openapi' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => env('APP_VERSION', '1.0.0'),
        ],
    ],
];
