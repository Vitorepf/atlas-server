<?php

return [
    'token' => env('ATLAS_TOKEN'),
    'storage_path' => env('ATLAS_STORAGE_PATH', '/var/atlas/storage'),
    'max_upload_bytes' => (int) env('ATLAS_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),

    'transcription' => [
        'enabled' => (bool) env('TRANSCRIPTION_ENABLED', false),
        'bin_path' => env('WHISPER_BIN_PATH', '/usr/local/bin/whisper-cli'),
        'model_path' => env('WHISPER_MODEL_PATH', '/opt/whisper-models/ggml-base.bin'),
        'ffmpeg_path' => env('FFMPEG_BIN_PATH', '/usr/bin/ffmpeg'),
        'language' => env('WHISPER_LANGUAGE', 'pt'),
        'engine' => env('WHISPER_ENGINE', 'whisper-cpp-base'),
    ],

    'semantic_memory' => [
        'vault_path' => env('ATLAS_VAULT_PATH', dirname(base_path()).'/AtlasVault'),
        'embedding_dimensions' => (int) env('ATLAS_SEMANTIC_EMBEDDING_DIMENSIONS', 1536),
        'max_embedding_chars' => (int) env('ATLAS_SEMANTIC_MAX_EMBEDDING_CHARS', 12000),
        'activation_daily_limit' => (int) env('ATLAS_SEMANTIC_ACTIVATION_DAILY_LIMIT', 2),
        'curation_min_content_chars' => (int) env('ATLAS_SEMANTIC_CURATION_MIN_CONTENT_CHARS', 160),
    ],

    'ai' => [
        'enabled' => (bool) env('ATLAS_AI_ENABLED', true),
        'default_provider' => env('ATLAS_AI_DEFAULT_PROVIDER', 'claude_cli'),
        'default_agent' => env('ATLAS_AI_DEFAULT_AGENT', 'orquestrador'),
        'workdir' => env('ATLAS_AI_WORKDIR', dirname(base_path())),
        'worker_id' => env('ATLAS_AI_WORKER_ID', gethostname() ?: 'atlas-worker'),
        'timeout_seconds' => (int) env('ATLAS_AI_TIMEOUT_SECONDS', 300),
        'max_attempts' => (int) env('ATLAS_AI_MAX_ATTEMPTS', 2),
        'retry_delay_seconds' => (int) env('ATLAS_AI_RETRY_DELAY_SECONDS', 300),
        'context_note_limit' => (int) env('ATLAS_AI_CONTEXT_NOTE_LIMIT', 5),
        'context_excerpt_chars' => (int) env('ATLAS_AI_CONTEXT_EXCERPT_CHARS', 1200),
        'schedule_worker' => (bool) env('ATLAS_AI_SCHEDULE_WORKER', false),
        'providers' => [
            'claude_cli' => [
                'binary' => env('ATLAS_AI_CLAUDE_BIN', 'claude'),
                'model' => env('ATLAS_AI_CLAUDE_MODEL', null),
                'args' => env('ATLAS_AI_CLAUDE_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CLAUDE_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['-p', '--output-format', 'json', '--no-session-persistence'],
            ],
            'codex_cli' => [
                'binary' => env('ATLAS_AI_CODEX_BIN', 'codex'),
                'model' => env('ATLAS_AI_CODEX_MODEL', null),
                'sandbox' => env('ATLAS_AI_CODEX_SANDBOX', 'read-only'),
                'args' => env('ATLAS_AI_CODEX_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CODEX_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['exec', '--skip-git-repo-check'],
            ],
        ],
    ],
];
