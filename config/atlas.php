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
];
