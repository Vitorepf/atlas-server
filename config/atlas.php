<?php

return [
    'version' => env('ATLAS_VERSION'),
    'token' => env('ATLAS_TOKEN'),
    'storage_path' => env('ATLAS_STORAGE_PATH', '/var/atlas/storage'),
    'max_upload_bytes' => (int) env('ATLAS_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),

    'transcription' => [
        'enabled' => (bool) env('TRANSCRIPTION_ENABLED', false),
        'bin_path' => env('WHISPER_BIN_PATH', '/usr/local/bin/whisper-cli'),
        'ffmpeg_path' => env('FFMPEG_BIN_PATH', '/usr/bin/ffmpeg'),
        'language' => env('WHISPER_LANGUAGE', 'pt'),
        'models_dir' => env('WHISPER_MODELS_DIR', '/opt/whisper-models'),
        'model_file' => 'ggml-large-v3-turbo.bin',
        'model_path' => file_exists(rtrim(env('WHISPER_MODELS_DIR', '/opt/whisper-models'), '/').'/ggml-large-v3-turbo.bin')
            ? rtrim(env('WHISPER_MODELS_DIR', '/opt/whisper-models'), '/').'/ggml-large-v3-turbo.bin'
            : '/opt/whisper-models/ggml-large-v3-turbo.bin',
        'engine' => 'whisper-cpp-large-v3-turbo',
        'timeout_seconds' => (int) env('WHISPER_TIMEOUT_SECONDS', 3600),
        'normalize_timeout_seconds' => (int) env('WHISPER_NORMALIZE_TIMEOUT_SECONDS', 300),
    ],

    'youtube' => [
        'enabled' => (bool) env('ATLAS_YOUTUBE_INGESTION_ENABLED', true),
        'data_api_enabled' => (bool) env('ATLAS_YOUTUBE_DATA_API_ENABLED', false),
        'data_api_key' => env('YOUTUBE_DATA_API_KEY'),
        'data_api_daily_unit_limit' => (int) env('ATLAS_YOUTUBE_DATA_API_DAILY_UNIT_LIMIT', 500),
        'queue' => env('ATLAS_YOUTUBE_QUEUE', 'transcription'),
        'cache_enabled' => (bool) env('ATLAS_YOUTUBE_CACHE_ENABLED', true),
        'cache_ttl_days' => (int) env('ATLAS_YOUTUBE_CACHE_TTL_DAYS', 30),
        'yt_dlp_binary' => env('ATLAS_YOUTUBE_YTDLP_BINARY'),
        'timeout_seconds' => (int) env('ATLAS_YOUTUBE_TIMEOUT_SECONDS', 35),
        'max_videos_per_turn' => (int) env('ATLAS_YOUTUBE_MAX_VIDEOS_PER_TURN', 2),
        'preferred_caption_languages' => env('ATLAS_YOUTUBE_PREFERRED_CAPTION_LANGUAGES', 'pt-BR,pt,en,ja,zh-Hans,zh-Hant,zh'),
        'chunk_seconds' => (int) env('ATLAS_YOUTUBE_CHUNK_SECONDS', 300),
        'max_chunk_chars' => (int) env('ATLAS_YOUTUBE_MAX_CHUNK_CHARS', 5000),
        'max_chunks' => (int) env('ATLAS_YOUTUBE_MAX_CHUNKS', 80),
        'max_transcript_chars' => (int) env('ATLAS_YOUTUBE_MAX_TRANSCRIPT_CHARS', 120000),
        'audio_fallback_enabled' => (bool) env('ATLAS_YOUTUBE_AUDIO_FALLBACK_ENABLED', (bool) env('TRANSCRIPTION_ENABLED', false)),
        'defer_audio_fallback' => (bool) env('ATLAS_YOUTUBE_DEFER_AUDIO_FALLBACK', true),
        'processing_lock_minutes' => (int) env('ATLAS_YOUTUBE_PROCESSING_LOCK_MINUTES', 90),
        'audio_download_timeout_seconds' => (int) env('ATLAS_YOUTUBE_AUDIO_DOWNLOAD_TIMEOUT_SECONDS', 300),
        'audio_download_retries' => (int) env('ATLAS_YOUTUBE_AUDIO_DOWNLOAD_RETRIES', 2),
        'caption_download_retries' => (int) env('ATLAS_YOUTUBE_CAPTION_DOWNLOAD_RETRIES', 2),
        'caption_download_retry_sleep_ms' => (int) env('ATLAS_YOUTUBE_CAPTION_DOWNLOAD_RETRY_SLEEP_MS', 700),
        'audio_transcription_realtime_ratio' => (float) env('ATLAS_YOUTUBE_AUDIO_TRANSCRIPTION_REALTIME_RATIO', 0.65),
        'max_audio_duration_seconds' => (int) env('ATLAS_YOUTUBE_MAX_AUDIO_DURATION_SECONDS', 7200),
    ],

    'semantic_memory' => [
        'vault_path' => env('ATLAS_VAULT_PATH', dirname(base_path()).'/AtlasVault'),
        'embedding_dimensions' => (int) env('ATLAS_SEMANTIC_EMBEDDING_DIMENSIONS', 1536),
        'embedding_provider' => env('ATLAS_SEMANTIC_EMBEDDING_PROVIDER', 'local_hash'),
        'embedding_model' => env('ATLAS_SEMANTIC_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_api_key' => env('OPENAI_API_KEY'),
        'embedding_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_timeout_seconds' => (int) env('ATLAS_SEMANTIC_EMBEDDING_TIMEOUT_SECONDS', 20),
        'embedding_fallback_enabled' => (bool) env('ATLAS_SEMANTIC_EMBEDDING_FALLBACK_ENABLED', true),
        'max_embedding_chars' => (int) env('ATLAS_SEMANTIC_MAX_EMBEDDING_CHARS', 12000),
        'activation_daily_limit' => (int) env('ATLAS_SEMANTIC_ACTIVATION_DAILY_LIMIT', 2),
        'activation_pending_limit' => (int) env('ATLAS_SEMANTIC_ACTIVATION_PENDING_LIMIT', 4),
        'activation_note_cooldown_days' => (int) env('ATLAS_SEMANTIC_ACTIVATION_NOTE_COOLDOWN_DAYS', 7),
        'curation_min_content_chars' => (int) env('ATLAS_SEMANTIC_CURATION_MIN_CONTENT_CHARS', 160),
        'curation_min_density_score' => (float) env('ATLAS_SEMANTIC_CURATION_MIN_DENSITY_SCORE', 0.42),
        'enqueue_ai_clarification' => (bool) env('ATLAS_SEMANTIC_ENQUEUE_AI_CLARIFICATION', false),
    ],

    'domains' => [
        'defaults' => [
            [
                'slug' => 'blackink',
                'label' => 'BlackInk',
                'description' => 'Produto, SaaS, clientes, posicionamento, growth, suporte e estrategia da Black Ink.',
                'color_light' => '#1B3A57',
                'color_dark' => '#6892B5',
                'default_sensitivity' => env('ATLAS_PRIVACY_BLACKINK_DEFAULT', 'private'),
                'external_ai_policy' => 'block_private_sensitive',
                'sort_order' => 10,
                'active' => true,
            ],
            [
                'slug' => 'atlas',
                'label' => 'Atlas',
                'description' => 'Arquitetura, produto, memoria, inbox, agentes, decisoes internas e evolucao do proprio Atlas.',
                'color_light' => '#5D4A8A',
                'color_dark' => '#B6A6E8',
                'default_sensitivity' => env('ATLAS_PRIVACY_ATLAS_DEFAULT', 'private'),
                'external_ai_policy' => 'block_private_sensitive',
                'sort_order' => 20,
                'active' => true,
            ],
            [
                'slug' => 'saude',
                'label' => 'Saúde',
                'description' => 'Sono, treino, energia, sintomas, saude subjetiva e sinais fisiologicos.',
                'color_light' => '#4A5D3A',
                'color_dark' => '#7A9A65',
                'default_sensitivity' => env('ATLAS_PRIVACY_SAUDE_DEFAULT', 'sensitive'),
                'external_ai_policy' => 'block_private_sensitive',
                'sort_order' => 30,
                'active' => true,
            ],
            [
                'slug' => 'financas',
                'label' => 'Finanças',
                'description' => 'Mercado, carteira, decisoes financeiras, riscos, tese de investimento e patrimonio.',
                'color_light' => '#9B7A3F',
                'color_dark' => '#C9A663',
                'default_sensitivity' => env('ATLAS_PRIVACY_FINANCAS_DEFAULT', 'private'),
                'external_ai_policy' => 'block_private_sensitive',
                'sort_order' => 40,
                'active' => true,
            ],
            [
                'slug' => 'outro',
                'label' => 'Outro',
                'description' => 'Capturas sem dominio definido ou assunto transversal.',
                'color_light' => '#6B6358',
                'color_dark' => '#A89F90',
                'default_sensitivity' => env('ATLAS_PRIVACY_OUTRO_DEFAULT', 'normal'),
                'external_ai_policy' => 'allow',
                'sort_order' => 999,
                'active' => true,
            ],
        ],
    ],

    'privacy' => [
        'block_external_ai_for_sensitivity' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_PRIVACY_BLOCK_EXTERNAL_AI_FOR', 'private,sensitive')),
        ))),
    ],

    'open_brain' => [
        'injection' => [
            'enabled' => (bool) env('ATLAS_OPEN_BRAIN_INJECTION_ENABLED', true),
            'budget_chars' => (int) env('ATLAS_OPEN_BRAIN_INJECTION_BUDGET_CHARS', 20000),
            'required_for_complete' => (bool) env('ATLAS_OPEN_BRAIN_INJECTION_REQUIRED_FOR_COMPLETE', true),
            'knowledge_ref_limit' => (int) env('ATLAS_OPEN_BRAIN_INJECTION_KNOWLEDGE_REF_LIMIT', 6),
            'code_ref_limit' => (int) env('ATLAS_OPEN_BRAIN_INJECTION_CODE_REF_LIMIT', 8),
            'include_memory_quality' => (bool) env('ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_MEMORY_QUALITY', true),
        ],
        'mcp' => [
            'http_enabled' => (bool) env('ATLAS_OPEN_BRAIN_MCP_HTTP_ENABLED', true),
            'allowed_origins' => env('ATLAS_OPEN_BRAIN_MCP_ALLOWED_ORIGINS')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_OPEN_BRAIN_MCP_ALLOWED_ORIGINS'))), fn (string $origin): bool => $origin !== ''))
                : [
                    'http://localhost',
                    'http://127.0.0.1',
                    'http://localhost:3000',
                    'http://127.0.0.1:3000',
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                ],
        ],
    ],

    'display' => [
        'busy_input_mode' => env('ATLAS_BUSY_INPUT_MODE', 'interrupt'),
    ],

    'voice' => [
        'livekit' => [
            'token_issuer_enabled' => (bool) env('ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED', false),
            'url' => env('LIVEKIT_URL'),
            'public_url' => env('ATLAS_VOICE_LIVEKIT_PUBLIC_URL', env('LIVEKIT_PUBLIC_URL', env('LIVEKIT_URL'))),
            'api_key' => env('LIVEKIT_API_KEY'),
            'api_secret' => env('LIVEKIT_API_SECRET'),
            'token_ttl_seconds' => (int) env('ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS', 900),
            'agent_name' => env('ATLAS_VOICE_LIVEKIT_AGENT_NAME', 'atlas-voice-agent'),
        ],
    ],

    // Atlas Vox V3 governed_execute executors. Each entry is opt-in: if
    // `binary` is not set OR not executable, the executor reports
    // unavailable and the Kernel returns a structured `blocked` outcome
    // instead of pretending to run. The Kernel NEVER forwards API keys —
    // the CLI is expected to be locally authenticated via its own config.
    'vox' => [
        'executors' => [
            'codex_cli' => [
                'binary' => env('ATLAS_VOX_CODEX_CLI_BIN'),
                'cwd' => env('ATLAS_VOX_CODEX_CLI_CWD'),
                'timeout_seconds' => (int) env('ATLAS_VOX_CODEX_CLI_TIMEOUT_SECONDS', 60),
            ],
            'claude_cli' => [
                'binary' => env('ATLAS_VOX_CLAUDE_CLI_BIN'),
                'cwd' => env('ATLAS_VOX_CLAUDE_CLI_CWD'),
                'timeout_seconds' => (int) env('ATLAS_VOX_CLAUDE_CLI_TIMEOUT_SECONDS', 60),
            ],
        ],
    ],

    'engineering' => [
        'docker' => [
            'default_service' => env('ATLAS_ENGINEERING_DOCKER_SERVICE'),
            'default_image' => env('ATLAS_ENGINEERING_DOCKER_IMAGE'),
            'workdir' => env('ATLAS_ENGINEERING_DOCKER_WORKDIR', '/workspace'),
            'cache' => [
                'mode' => env('ATLAS_ENGINEERING_DOCKER_CACHE', 'auto'),
            ],
            'network' => env('ATLAS_ENGINEERING_DOCKER_NETWORK', 'profile'),
            'healthcheck_services' => env('ATLAS_ENGINEERING_DOCKER_HEALTHCHECK_SERVICES')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_ENGINEERING_DOCKER_HEALTHCHECK_SERVICES'))), fn (string $service): bool => $service !== ''))
                : [],
            'healthcheck_timeout_seconds' => (int) env('ATLAS_ENGINEERING_DOCKER_HEALTHCHECK_TIMEOUT_SECONDS', 45),
            'artifact_paths' => env('ATLAS_ENGINEERING_DOCKER_ARTIFACT_PATHS')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_ENGINEERING_DOCKER_ARTIFACT_PATHS'))), fn (string $path): bool => $path !== ''))
                : ['coverage', 'test-results', 'playwright-report', 'reports', 'build/reports', 'junit.xml'],
            'artifact_max_files' => (int) env('ATLAS_ENGINEERING_DOCKER_ARTIFACT_MAX_FILES', 100),
            'artifact_max_bytes' => (int) env('ATLAS_ENGINEERING_DOCKER_ARTIFACT_MAX_BYTES', 10_485_760),
            'cleanup' => [
                'cache_retention_days' => (int) env('ATLAS_ENGINEERING_DOCKER_CACHE_RETENTION_DAYS', 14),
                'artifact_retention_days' => (int) env('ATLAS_ENGINEERING_DOCKER_ARTIFACT_RETENTION_DAYS', 30),
            ],
        ],
        'provider_runtime' => [
            'default' => env('ATLAS_ENGINEERING_PROVIDER_RUNTIME', 'host'),
            'docker' => [
                'compose_file' => env('ATLAS_ENGINEERING_PROVIDER_DOCKER_COMPOSE_FILE', base_path('docker-compose.yml')),
                'service' => env('ATLAS_ENGINEERING_PROVIDER_DOCKER_SERVICE', 'backend'),
                'app_dir' => env('ATLAS_ENGINEERING_PROVIDER_DOCKER_APP_DIR', '/app'),
                'workspace_dir' => env('ATLAS_ENGINEERING_PROVIDER_DOCKER_WORKSPACE_DIR', '/workspace'),
            ],
        ],
        'visual_e2e' => [
            'mode' => env('ATLAS_ENGINEERING_VISUAL_E2E', 'auto'),
            'managed_smoke_enabled' => (bool) env('ATLAS_ENGINEERING_VISUAL_SMOKE_ENABLED', true),
            'managed_smoke_timeout_seconds' => (int) env('ATLAS_ENGINEERING_VISUAL_SMOKE_TIMEOUT_SECONDS', 45),
            'baseline_mode' => env('ATLAS_ENGINEERING_VISUAL_BASELINE_MODE', 'observe'),
            'screenshot_driver' => env('ATLAS_ENGINEERING_VISUAL_SCREENSHOT_DRIVER', 'auto'),
            'playwright_node_modules' => env('ATLAS_ENGINEERING_VISUAL_PLAYWRIGHT_NODE_MODULES')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_ENGINEERING_VISUAL_PLAYWRIGHT_NODE_MODULES'))), fn (string $path): bool => $path !== ''))
                : [base_path('node_modules'), storage_path('app/engineering-playwright/node_modules')],
            'artifact_paths' => env('ATLAS_ENGINEERING_VISUAL_E2E_ARTIFACT_PATHS')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_ENGINEERING_VISUAL_E2E_ARTIFACT_PATHS'))), fn (string $path): bool => $path !== ''))
                : ['playwright-report', 'test-results', 'cypress/screenshots', 'cypress/videos', 'atlas-visual-report'],
            'artifact_max_files' => (int) env('ATLAS_ENGINEERING_VISUAL_E2E_ARTIFACT_MAX_FILES', 200),
            'artifact_max_bytes' => (int) env('ATLAS_ENGINEERING_VISUAL_E2E_ARTIFACT_MAX_BYTES', 52_428_800),
        ],
        'quality_scan' => [
            'mode' => env('ATLAS_ENGINEERING_QUALITY_SCAN', 'off'),
            'profile' => env('ATLAS_ENGINEERING_QUALITY_SCAN_PROFILE', 'auto'),
            'changed_only' => (bool) env('ATLAS_ENGINEERING_QUALITY_SCAN_CHANGED_ONLY', true),
            'timeout_seconds' => (int) env('ATLAS_ENGINEERING_QUALITY_SCAN_TIMEOUT_SECONDS', 300),
            'artifact_max_files' => (int) env('ATLAS_ENGINEERING_QUALITY_SCAN_ARTIFACT_MAX_FILES', 100),
            'artifact_max_bytes' => (int) env('ATLAS_ENGINEERING_QUALITY_SCAN_ARTIFACT_MAX_BYTES', 10_485_760),
        ],
    ],

    'attachments' => [
        'pdf' => [
            'ocr_languages' => env('ATLAS_PDF_OCR_LANGUAGES', 'por+eng'),
            'vision_page_limit' => (int) env('ATLAS_PDF_VISION_PAGE_LIMIT', 24),
            'background_processing_enabled' => (bool) env('ATLAS_ATTACHMENT_BACKGROUND_PROCESSING_ENABLED', true),
        ],
        'chunked_upload' => [
            'enabled' => (bool) env('ATLAS_CHUNKED_UPLOAD_ENABLED', true),
            'chunk_max_bytes' => (int) env('ATLAS_CHUNKED_UPLOAD_MAX_CHUNK_BYTES', 1_572_864),
            'ttl_hours' => (int) env('ATLAS_CHUNKED_UPLOAD_TTL_HOURS', 24),
        ],
    ],

    'mobile' => [
        'enabled' => (bool) env('ATLAS_MOBILE_ENABLED', false),
        'pairing_ttl_minutes' => (int) env('ATLAS_MOBILE_PAIRING_TTL_MINUTES', 60),
        'approval_ttl_minutes' => (int) env('ATLAS_MOBILE_APPROVAL_TTL_MINUTES', 30),
        'max_devices' => (int) env('ATLAS_MOBILE_MAX_DEVICES', 5),
        'push_provider' => env('ATLAS_MOBILE_PUSH_PROVIDER', 'expo'),
        'rate_limits' => [
            'pairing_initiate_max_attempts' => (int) env('ATLAS_MOBILE_PAIRING_INITIATE_MAX_ATTEMPTS', 12),
            'pairing_initiate_decay_seconds' => (int) env('ATLAS_MOBILE_PAIRING_INITIATE_DECAY_SECONDS', 600),
            'pairing_confirm_max_attempts' => (int) env('ATLAS_MOBILE_PAIRING_CONFIRM_MAX_ATTEMPTS', 12),
            'pairing_confirm_decay_seconds' => (int) env('ATLAS_MOBILE_PAIRING_CONFIRM_DECAY_SECONDS', 600),
            'sensitive_action_max_attempts' => (int) env('ATLAS_MOBILE_SENSITIVE_ACTION_MAX_ATTEMPTS', 20),
            'sensitive_action_decay_seconds' => (int) env('ATLAS_MOBILE_SENSITIVE_ACTION_DECAY_SECONDS', 300),
        ],
        'push_receipts' => [
            'enabled' => (bool) env('ATLAS_MOBILE_PUSH_RECEIPTS_ENABLED', false),
        ],
        'quiet_hours' => [
            'enabled' => (bool) env('ATLAS_MOBILE_QUIET_ENABLED', false),
            'start' => env('ATLAS_MOBILE_QUIET_START', '22:00'),
            'end' => env('ATLAS_MOBILE_QUIET_END', '07:00'),
            'severity_threshold' => env('ATLAS_MOBILE_QUIET_SEVERITY_THRESHOLD', 'critical'),
        ],
        'batching' => [
            'enabled' => (bool) env('ATLAS_MOBILE_BATCH_ENABLED', true),
            'window_minutes' => (int) env('ATLAS_MOBILE_BATCH_WINDOW_MINUTES', 15),
        ],
        'maintenance' => [
            'expire_stale_enabled' => (bool) env('ATLAS_MOBILE_EXPIRE_STALE_ENABLED', true),
            'cleanup_enabled' => (bool) env('ATLAS_MOBILE_CLEANUP_ENABLED', true),
            'cleanup_time' => env('ATLAS_MOBILE_CLEANUP_TIME', '03:30'),
        ],
        'alerts' => [
            'enabled' => (bool) env('ATLAS_MOBILE_ALERTS_ENABLED', true),
            'webhook_url' => env('ATLAS_MOBILE_ALERT_WEBHOOK_URL'),
            'local_log_enabled' => (bool) env('ATLAS_MOBILE_ALERT_LOCAL_LOG_ENABLED', true),
            'local_log_path' => env('ATLAS_MOBILE_ALERT_LOCAL_LOG_PATH') ?: storage_path('logs/atlas-health-alerts.jsonl'),
            'cooldown_minutes' => (int) env('ATLAS_MOBILE_ALERT_COOLDOWN_MINUTES', 30),
            'http_timeout_seconds' => (int) env('ATLAS_MOBILE_ALERT_HTTP_TIMEOUT_SECONDS', 5),
            'scheduler_stale_minutes' => (int) env('ATLAS_MOBILE_ALERT_SCHEDULER_STALE_MINUTES', 5),
            'push_sample_size' => (int) env('ATLAS_MOBILE_ALERT_PUSH_SAMPLE_SIZE', 20),
            'push_min_sample' => (int) env('ATLAS_MOBILE_ALERT_PUSH_MIN_SAMPLE', 5),
            'push_success_rate_threshold' => (float) env('ATLAS_MOBILE_ALERT_PUSH_SUCCESS_RATE_THRESHOLD', 0.5),
            'circuit_stuck_minutes' => (int) env('ATLAS_MOBILE_ALERT_CIRCUIT_STUCK_MINUTES', 30),
            'jobs_silent_hours' => (int) env('ATLAS_MOBILE_ALERT_JOBS_SILENT_HOURS', 6),
        ],
        'cleanup' => [
            'pairing_codes_after_days' => (int) env('ATLAS_MOBILE_CLEANUP_PAIRING_CODES_AFTER_DAYS', 7),
            'inbox_resolved_after_days' => (int) env('ATLAS_MOBILE_CLEANUP_INBOX_RESOLVED_AFTER_DAYS', 90),
            'bundles_orphan_after_days' => (int) env('ATLAS_MOBILE_CLEANUP_BUNDLES_ORPHAN_AFTER_DAYS', 30),
            'deliveries_after_days' => (int) env('ATLAS_MOBILE_CLEANUP_DELIVERIES_AFTER_DAYS', 90),
        ],
        'circuit' => [
            'failure_threshold' => (int) env('ATLAS_MOBILE_CIRCUIT_FAILURE_THRESHOLD', 5),
            'failure_window_seconds' => (int) env('ATLAS_MOBILE_CIRCUIT_FAILURE_WINDOW_SECONDS', 60),
            'open_seconds' => (int) env('ATLAS_MOBILE_CIRCUIT_OPEN_SECONDS', 300),
        ],
        'retry' => [
            'queue_enabled' => (bool) env('ATLAS_MOBILE_RETRY_QUEUE_ENABLED', true),
            'backoff_seconds' => array_values(array_filter(array_map(
                static fn ($v): int => (int) trim((string) $v),
                explode(',', (string) env('ATLAS_MOBILE_RETRY_BACKOFF_SECONDS', '30,120,600,1800'))
            ), fn ($v): bool => $v > 0)),
        ],
        'self_diagnostic' => [
            'enabled' => (bool) env('ATLAS_INIT_SELF_DIAGNOSTIC', false),
            'time' => env('ATLAS_INIT_SELF_DIAGNOSTIC_TIME', '06:15'),
            'recent_days' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_RECENT_DAYS', 7),
            'baseline_days' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_BASELINE_DAYS', 30),
            'min_recent_samples' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_MIN_RECENT_SAMPLES', 4),
            'min_baseline_samples' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_MIN_BASELINE_SAMPLES', 6),
            'score_drop_threshold' => (float) env('ATLAS_INIT_SELF_DIAGNOSTIC_SCORE_DROP_THRESHOLD', 12),
            'failure_rate_increase_threshold' => (float) env('ATLAS_INIT_SELF_DIAGNOSTIC_FAILURE_RATE_INCREASE_THRESHOLD', 0.2),
            'confidence_threshold' => (float) env('ATLAS_INIT_SELF_DIAGNOSTIC_CONFIDENCE_THRESHOLD', 0.7),
        ],
        'proposal_scan' => [
            'enabled' => (bool) env('ATLAS_INIT_PROPOSAL_SCAN', false),
            'time' => env('ATLAS_INIT_PROPOSAL_SCAN_TIME', '06:30'),
            'workspace' => env('ATLAS_INIT_PROPOSAL_SCAN_WORKSPACE', dirname(base_path())),
            'limit' => (int) env('ATLAS_INIT_PROPOSAL_SCAN_LIMIT', 3),
            'large_service_lines' => (int) env('ATLAS_INIT_PROPOSAL_LARGE_SERVICE_LINES', 420),
        ],
        'insight_watch' => [
            'enabled' => (bool) env('ATLAS_INIT_INSIGHT_WATCH', false),
            'time' => env('ATLAS_INIT_INSIGHT_WATCH_TIME', '06:45'),
            'min_confidence' => (float) env('ATLAS_INIT_INSIGHT_MIN_CONFIDENCE', 0.65),
            'health_baseline_days' => (int) env('ATLAS_INIT_INSIGHT_HEALTH_BASELINE_DAYS', 14),
            'health_min_baseline_samples' => (int) env('ATLAS_INIT_INSIGHT_HEALTH_MIN_BASELINE_SAMPLES', 3),
            'health_readiness_drop_threshold' => (float) env('ATLAS_INIT_INSIGHT_HEALTH_READINESS_DROP_THRESHOLD', 15),
            'digital_baseline_days' => (int) env('ATLAS_INIT_INSIGHT_DIGITAL_BASELINE_DAYS', 14),
            'digital_min_baseline_samples' => (int) env('ATLAS_INIT_INSIGHT_DIGITAL_MIN_BASELINE_SAMPLES', 3),
            'digital_algorithmic_spike_minutes' => (float) env('ATLAS_INIT_INSIGHT_DIGITAL_ALGORITHMIC_SPIKE_MINUTES', 45),
            'provider_pain_threshold' => (int) env('ATLAS_INIT_INSIGHT_PROVIDER_PAIN_THRESHOLD', 70),
        ],
    ],

    'ai_metrics' => [
        'enabled' => (bool) env('ATLAS_AI_METRICS_ENABLED', true),
        'telemetry_strict_events' => (bool) env('ATLAS_AI_TELEMETRY_STRICT_EVENTS', false),
        'telemetry_max_batch' => (int) env('ATLAS_AI_TELEMETRY_MAX_BATCH', 100),
        'telemetry_max_metadata_bytes' => (int) env('ATLAS_AI_TELEMETRY_MAX_METADATA_BYTES', 12000),
        'summary_recompute_window_hours' => (int) env('ATLAS_AI_METRICS_RECOMPUTE_WINDOW_HOURS', 24),
        'cost_default_confidence' => env('ATLAS_AI_COST_DEFAULT_CONFIDENCE', 'estimated'),
        'cost_rates' => json_decode((string) env('ATLAS_AI_COST_RATES_JSON', '[]'), true) ?: [],
        'health_min_traces' => (int) env('ATLAS_AI_HEALTH_MIN_TRACES', 3),
        'health_quality_warning_below' => (float) env('ATLAS_AI_HEALTH_QUALITY_WARNING_BELOW', 70),
        'health_quality_critical_below' => (float) env('ATLAS_AI_HEALTH_QUALITY_CRITICAL_BELOW', 55),
        'health_efficiency_warning_below' => (float) env('ATLAS_AI_HEALTH_EFFICIENCY_WARNING_BELOW', 70),
        'health_efficiency_critical_below' => (float) env('ATLAS_AI_HEALTH_EFFICIENCY_CRITICAL_BELOW', 55),
        'health_first_pass_warning_below' => (float) env('ATLAS_AI_HEALTH_FIRST_PASS_WARNING_BELOW', 0.65),
        'health_first_pass_critical_below' => (float) env('ATLAS_AI_HEALTH_FIRST_PASS_CRITICAL_BELOW', 0.45),
        'health_remediation_warning_above' => (float) env('ATLAS_AI_HEALTH_REMEDIATION_WARNING_ABOVE', 0.25),
        'health_remediation_critical_above' => (float) env('ATLAS_AI_HEALTH_REMEDIATION_CRITICAL_ABOVE', 0.45),
        'health_unknown_cost_warning_above' => (float) env('ATLAS_AI_HEALTH_UNKNOWN_COST_WARNING_ABOVE', 0.5),
        'health_unknown_cost_critical_above' => (float) env('ATLAS_AI_HEALTH_UNKNOWN_COST_CRITICAL_ABOVE', 0.9),
        'health_app_visible_warning_above_ms' => (int) env('ATLAS_AI_HEALTH_APP_VISIBLE_WARNING_ABOVE_MS', 30000),
        'health_app_visible_critical_above_ms' => (int) env('ATLAS_AI_HEALTH_APP_VISIBLE_CRITICAL_ABOVE_MS', 120000),
        'health_slow_trace_warning_rate_above' => (float) env('ATLAS_AI_HEALTH_SLOW_TRACE_WARNING_RATE_ABOVE', 0.1),
        'health_low_quality_warning_rate_above' => (float) env('ATLAS_AI_HEALTH_LOW_QUALITY_WARNING_RATE_ABOVE', 0.1),
        // Tool denial rate thresholds — Fix 7c F4. Default 15% / min 10 calls (decision #2):
        //  - 10% too sensitive at low volume (1 denial in 10 trips warning)
        //  - 20% only fires when problem is already significant
        //  - 15% with min N=10 = at least 2 denials in 10 calls before warning
        // Tunable per environment without deploy.
        'tool_denial_warning_above' => (float) env('ATLAS_AI_TOOL_DENIAL_WARNING_ABOVE', 0.15),
        'tool_denial_critical_above' => (float) env('ATLAS_AI_TOOL_DENIAL_CRITICAL_ABOVE', 0.40),
        'tool_denial_min_calls' => (int) env('ATLAS_AI_TOOL_DENIAL_MIN_CALLS', 10),
        // Tool failure rate thresholds — same logic, slightly higher tolerance
        // because some failures are intentional (probe pattern, expected ENOENT).
        'tool_failure_warning_above' => (float) env('ATLAS_AI_TOOL_FAILURE_WARNING_ABOVE', 0.20),
        'tool_failure_critical_above' => (float) env('ATLAS_AI_TOOL_FAILURE_CRITICAL_ABOVE', 0.50),
        'tool_failure_min_calls' => (int) env('ATLAS_AI_TOOL_FAILURE_MIN_CALLS', 10),
        'performance_report_enabled' => (bool) env('ATLAS_AI_PERFORMANCE_REPORT_ENABLED', true),
        'performance_report_emit' => (bool) env('ATLAS_AI_PERFORMANCE_REPORT_EMIT', true),
        'performance_report_time' => env('ATLAS_AI_PERFORMANCE_REPORT_TIME', '07:05'),
        'performance_report_timezone' => env('ATLAS_AI_PERFORMANCE_REPORT_TIMEZONE', env('RIZE_TIMEZONE', env('APP_TIMEZONE', 'UTC'))),
        'performance_report_grace_minutes' => (int) env('ATLAS_AI_PERFORMANCE_REPORT_GRACE_MINUTES', 90),
        'performance_report_alert_require_history' => (bool) env('ATLAS_AI_PERFORMANCE_REPORT_ALERT_REQUIRE_HISTORY', true),
        'snapshot_refresh_enabled' => (bool) env('ATLAS_AI_SNAPSHOT_REFRESH_ENABLED', true),
        'snapshot_refresh_time' => env('ATLAS_AI_SNAPSHOT_REFRESH_TIME', '06:50'),
        'performance_report_windows' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('ATLAS_AI_PERFORMANCE_REPORT_WINDOWS', '3,7,15,30')),
        ), fn (int $value): bool => $value > 0 && $value <= 365)),
        'evals_enabled' => (bool) env('ATLAS_AI_EVALS_ENABLED', true),
        'provider_ab_enabled' => (bool) env('ATLAS_AI_PROVIDER_AB_ENABLED', false),
        'mobile_outbox_max_events' => (int) env('ATLAS_AI_MOBILE_OUTBOX_MAX_EVENTS', 500),
        'cli_outbox_max_files' => (int) env('ATLAS_AI_CLI_OUTBOX_MAX_FILES', 500),
    ],

    // Engine de Relatório (introduzido na Phase 0). Single feature flag controla rollout
    // em 5 fases: legacy = comportamento atual sem mudança; shadow = engine roda em
    // paralelo logando resultados sem alterar payload; next = engine substitui code path
    // legado e bumpa schema_version 1 → 2. Cada fase ship é independentemente reversível
    // mudando essa única chave. Decisão #4 da síntese das 5 lentes (4/5 a favor de single
    // flag — cognitive load simples, semântica clara, dead code paths impossíveis).
    'report' => [
        'engine_version' => env('ATLAS_REPORT_ENGINE_VERSION', 'legacy'),
        'engine_run_mode' => env('ATLAS_REPORT_ENGINE_RUN_MODE'),
        // Janelas de medição de recomendação por kind. Decisão #13: por kind venceu
        // 3/5 (rigor estatístico) com concessão pragmatismo: hardcoded em config, não
        // 3 cron jobs separados. Single cron lê esses valores por kind ao agendar
        // measurement_due_at. Esses defaults derivam do consenso UX/Risk/Arq:
        //   - cost: 3 dias (custo é determinístico, sem sazonalidade longa)
        //   - latency: 7 dias (padrão semanal de uso)
        //   - quality: 14 dias (feedback humano tem ciclo semanal, precisa 2 ciclos)
        'recommendation_measurement_window_days' => [
            'cost' => (int) env('ATLAS_REPORT_REC_WINDOW_COST_DAYS', 3),
            'latency' => (int) env('ATLAS_REPORT_REC_WINDOW_LATENCY_DAYS', 7),
            'quality' => (int) env('ATLAS_REPORT_REC_WINDOW_QUALITY_DAYS', 14),
            'default' => (int) env('ATLAS_REPORT_REC_WINDOW_DEFAULT_DAYS', 7),
        ],
        'recommendation_measure_enabled' => (bool) env('ATLAS_REPORT_REC_MEASURE_ENABLED', true),
        'recommendation_measure_time' => env('ATLAS_REPORT_REC_MEASURE_TIME', '06:40'),
        'recommendation_measure_min_samples' => (int) env('ATLAS_REPORT_REC_MEASURE_MIN_SAMPLES', 3),
        'recommendation_min_effect_fraction' => (float) env('ATLAS_REPORT_REC_MIN_EFFECT_FRACTION', 0.02),
        'recommendation_inbox_enabled' => (bool) env('ATLAS_REPORT_REC_INBOX_ENABLED', true),
        // Gain threshold pra finding ser surfaced (Decisão #10). 35% venceu 3/5 por
        // assimetria de custo: false positive de recomendação queima credibilidade do
        // canal mais do que false negative custa em sinal perdido (sinal volta).
        'finding_min_gain_fraction' => (float) env('ATLAS_REPORT_FINDING_MIN_GAIN', 0.35),
    ],

    'ai' => [
        'enabled' => (bool) env('ATLAS_AI_ENABLED', true),
        'default_provider' => env('ATLAS_AI_DEFAULT_PROVIDER', 'claude_cli'),
        'default_tier' => env('ATLAS_AI_DEFAULT_TIER', 'daily'),
        'council_allow_auto' => (bool) env('ATLAS_AI_COUNCIL_ALLOW_AUTO', false),
        'budget' => [
            'enabled' => (bool) env('ATLAS_AI_BUDGET_ENABLED', false),
            'mode' => env('ATLAS_AI_BUDGET_MODE', 'block'),
            'window_hours' => (int) env('ATLAS_AI_BUDGET_WINDOW_HOURS', 24),
            'max_visible_tokens' => env('ATLAS_AI_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_BUDGET_MAX_VISIBLE_TOKENS') : null,
            'warn_visible_tokens' => env('ATLAS_AI_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_BUDGET_WARN_VISIBLE_TOKENS') : null,
            'providers' => [
                'claude_cli' => [
                    'max_visible_tokens' => env('ATLAS_AI_CLAUDE_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_CLAUDE_BUDGET_MAX_VISIBLE_TOKENS') : null,
                    'warn_visible_tokens' => env('ATLAS_AI_CLAUDE_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_CLAUDE_BUDGET_WARN_VISIBLE_TOKENS') : null,
                ],
                'codex_cli' => [
                    'max_visible_tokens' => env('ATLAS_AI_CODEX_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_CODEX_BUDGET_MAX_VISIBLE_TOKENS') : null,
                    'warn_visible_tokens' => env('ATLAS_AI_CODEX_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_CODEX_BUDGET_WARN_VISIBLE_TOKENS') : null,
                ],
                'gemini_cli' => [
                    'max_visible_tokens' => env('ATLAS_AI_GEMINI_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_GEMINI_BUDGET_MAX_VISIBLE_TOKENS') : null,
                    'warn_visible_tokens' => env('ATLAS_AI_GEMINI_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_GEMINI_BUDGET_WARN_VISIBLE_TOKENS') : null,
                ],
                'jarvis_mlx' => [
                    'max_visible_tokens' => env('ATLAS_AI_JARVIS_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_JARVIS_BUDGET_MAX_VISIBLE_TOKENS') : null,
                    'warn_visible_tokens' => env('ATLAS_AI_JARVIS_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_JARVIS_BUDGET_WARN_VISIBLE_TOKENS') : null,
                ],
            ],
        ],
        'default_agent' => env('ATLAS_AI_DEFAULT_AGENT', 'orquestrador'),
        'workdir' => env('ATLAS_AI_WORKDIR', dirname(base_path())),
        'worker_id' => env('ATLAS_AI_WORKER_ID', gethostname() ?: 'atlas-worker'),
        // 7200s (2h) era irreal e travava o request HTTP por horas em
        // caso de provider hung. 600s cobre 99% dos jobs reais; quem
        // precisar de mais (ex: tarefa agendada longa) sobe via
        // ATLAS_AI_TIMEOUT_SECONDS no env.
        'timeout_seconds' => (int) env('ATLAS_AI_TIMEOUT_SECONDS', 600),
        'max_attempts' => (int) env('ATLAS_AI_MAX_ATTEMPTS', 1),
        'retry_delay_seconds' => (int) env('ATLAS_AI_RETRY_DELAY_SECONDS', 300),
        'decision_receipt_ttl_seconds' => (int) env('ATLAS_AI_DECISION_RECEIPT_TTL_SECONDS', 7200),
        'decision_receipt_refresh_window_seconds' => (int) env('ATLAS_AI_DECISION_RECEIPT_REFRESH_WINDOW_SECONDS', 21600),
        'context_note_limit' => (int) env('ATLAS_AI_CONTEXT_NOTE_LIMIT', 5),
        'context_excerpt_chars' => (int) env('ATLAS_AI_CONTEXT_EXCERPT_CHARS', 1200),
        'memory_registry_limit' => (int) env('ATLAS_AI_MEMORY_REGISTRY_LIMIT', 8),
        'memory_registry_excerpt_chars' => (int) env('ATLAS_AI_MEMORY_REGISTRY_EXCERPT_CHARS', 900),
        'verbatim_recall_limit' => (int) env('ATLAS_AI_VERBATIM_RECALL_LIMIT', 4),
        'verbatim_recall_budget_chars' => (int) env('ATLAS_AI_VERBATIM_RECALL_BUDGET_CHARS', 1600),
        'verbatim_recall_item_chars' => (int) env('ATLAS_AI_VERBATIM_RECALL_ITEM_CHARS', 600),
        'memory_recall_limit' => (int) env('ATLAS_AI_MEMORY_RECALL_LIMIT', 10),
        'memory_recall_budget_chars' => (int) env('ATLAS_AI_MEMORY_RECALL_BUDGET_CHARS', 2400),
        'memory_recall_item_chars' => (int) env('ATLAS_AI_MEMORY_RECALL_ITEM_CHARS', 360),
        'provider_projection_max_lines' => (int) env('ATLAS_AI_PROVIDER_PROJECTION_MAX_LINES', 80),
        'provider_projection_memory_limit' => (int) env('ATLAS_AI_PROVIDER_PROJECTION_MEMORY_LIMIT', 18),
        'provider_projection_memory_chars' => (int) env('ATLAS_AI_PROVIDER_PROJECTION_MEMORY_CHARS', 220),
        'provider_projection_audit_purge' => [
            'require_operator' => (bool) env('ATLAS_AI_PROVIDER_PROJECTION_AUDIT_PURGE_REQUIRE_OPERATOR', false),
            'operator_header' => env('ATLAS_AI_PROVIDER_PROJECTION_AUDIT_PURGE_OPERATOR_HEADER', 'X-Atlas-Operator'),
            'operator_token' => env('ATLAS_AI_PROVIDER_PROJECTION_AUDIT_PURGE_OPERATOR_TOKEN'),
        ],
        'context_recent_turn_limit' => (int) env('ATLAS_AI_CONTEXT_RECENT_TURN_LIMIT', 12),
        'context_payload_turn_limit' => (int) env('ATLAS_AI_CONTEXT_PAYLOAD_TURN_LIMIT', 8),
        'session_idle_minutes' => (int) env('ATLAS_AI_SESSION_IDLE_MINUTES', 360),
        'auto_compaction_message_threshold' => (int) env('ATLAS_AI_AUTO_COMPACTION_MESSAGE_THRESHOLD', 18),
        'auto_compaction_messages_since_last' => (int) env('ATLAS_AI_AUTO_COMPACTION_MESSAGES_SINCE_LAST', 10),
        'quality_auto_remediation_max_depth' => (int) env('ATLAS_AI_QUALITY_AUTO_REMEDIATION_MAX_DEPTH', 1),
        'schedule_worker' => (bool) env('ATLAS_AI_SCHEDULE_WORKER', false),
        'scheduled_tasks' => [
            'timeout_seconds' => (int) env('ATLAS_AI_SCHEDULED_TASK_TIMEOUT_SECONDS', 600),
        ],
        'tool_permissions' => [
            'default_mode' => env('ATLAS_AI_TOOL_PERMISSION_MODE', 'danger'),
            'allow_danger' => (bool) env('ATLAS_AI_TOOL_ALLOW_DANGER', true),
            'allow_unsandboxed_write' => (bool) env('ATLAS_AI_ALLOW_UNSANDBOXED_WRITE', true),
            'allowed_roots' => env('ATLAS_AI_TOOL_ALLOWED_ROOTS')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_TOOL_ALLOWED_ROOTS'))), fn (string $root): bool => $root !== ''))
                : [dirname(dirname(dirname(base_path()))), dirname(dirname(base_path())), dirname(base_path()), base_path()],
            'codex_sandboxes' => [
                'read' => env('ATLAS_AI_CODEX_READ_SANDBOX', 'read-only'),
                'write' => env('ATLAS_AI_CODEX_WRITE_SANDBOX', 'workspace-write'),
                'danger' => env('ATLAS_AI_CODEX_DANGER_SANDBOX', 'danger-full-access'),
            ],
        ],
        'security' => [
            'process_env' => [
                'allowlist' => env('ATLAS_AI_PROCESS_ENV_ALLOWLIST')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_PROCESS_ENV_ALLOWLIST'))), fn (string $key): bool => $key !== ''))
                    : [
                        'PATH',
                        'HOME',
                        'USER',
                        'LOGNAME',
                        'LANG',
                        'LC_ALL',
                        'LC_CTYPE',
                        'TERM',
                        'SHELL',
                        'TMPDIR',
                        'SSH_AUTH_SOCK',
                        'XDG_CACHE_HOME',
                        'XDG_CONFIG_HOME',
                        'XDG_DATA_HOME',
                        'XDG_RUNTIME_DIR',
                    ],
                'prefix_allowlist' => ['XDG_', 'LC_'],
                'profiles' => [
                    'tool' => [
                        'allowlist' => [],
                        'prefix_allowlist' => [],
                    ],
                    'provider' => [
                        'allowlist' => [
                            'ANTHROPIC_API_KEY',
                            'ANTHROPIC_BASE_URL',
                            'OPENAI_API_KEY',
                            'OPENAI_BASE_URL',
                            'OPENAI_ORG_ID',
                            'OPENAI_PROJECT',
                            'CODEX_HOME',
                            'CLAUDE_CONFIG_DIR',
                            'GEMINI_API_KEY',
                            'GEMINI_CLI_HOME',
                            'GEMINI_HOME',
                            'GOOGLE_API_KEY',
                            'GOOGLE_CLOUD_PROJECT',
                            'GOOGLE_CLOUD_LOCATION',
                            'GOOGLE_GENAI_USE_VERTEXAI',
                            'CLAUDE_CODE_USE_BEDROCK',
                            'AWS_PROFILE',
                            'AWS_REGION',
                            'AWS_DEFAULT_REGION',
                            'AWS_ACCESS_KEY_ID',
                            'AWS_SECRET_ACCESS_KEY',
                            'AWS_SESSION_TOKEN',
                            'GOOGLE_APPLICATION_CREDENTIALS',
                        ],
                        'prefix_allowlist' => [],
                    ],
                    'internal' => [
                        'allowlist' => [
                            'APP_ENV',
                            'APP_DEBUG',
                            'APP_KEY',
                            'APP_URL',
                            'APP_MAINTENANCE_DRIVER',
                            'BCRYPT_ROUNDS',
                            'BROADCAST_CONNECTION',
                            'CACHE_STORE',
                            'CACHE_DRIVER',
                            'DB_CONNECTION',
                            'DB_HOST',
                            'DB_PORT',
                            'DB_DATABASE',
                            'DB_USERNAME',
                            'DB_PASSWORD',
                            'DB_URL',
                            'MAIL_MAILER',
                            'QUEUE_CONNECTION',
                            'SESSION_DRIVER',
                            'PULSE_ENABLED',
                            'TELESCOPE_ENABLED',
                            'NIGHTWATCH_ENABLED',
                            'ATLAS_TOKEN',
                            'ATLAS_AI_WORKDIR',
                            'ATLAS_AI_TOOL_ALLOWED_ROOTS',
                        ],
                        'prefix_allowlist' => [],
                    ],
                    'provider_runner' => [
                        'allowlist' => [
                            'APP_ENV',
                            'APP_DEBUG',
                            'APP_KEY',
                            'APP_URL',
                            'APP_MAINTENANCE_DRIVER',
                            'BCRYPT_ROUNDS',
                            'BROADCAST_CONNECTION',
                            'CACHE_STORE',
                            'CACHE_DRIVER',
                            'DB_CONNECTION',
                            'DB_HOST',
                            'DB_PORT',
                            'DB_DATABASE',
                            'DB_USERNAME',
                            'DB_PASSWORD',
                            'DB_URL',
                            'MAIL_MAILER',
                            'QUEUE_CONNECTION',
                            'SESSION_DRIVER',
                            'PULSE_ENABLED',
                            'TELESCOPE_ENABLED',
                            'NIGHTWATCH_ENABLED',
                            'ATLAS_TOKEN',
                            'ATLAS_AI_WORKDIR',
                            'ATLAS_AI_TOOL_ALLOWED_ROOTS',
                            'ANTHROPIC_API_KEY',
                            'ANTHROPIC_BASE_URL',
                            'OPENAI_API_KEY',
                            'OPENAI_BASE_URL',
                            'OPENAI_ORG_ID',
                            'OPENAI_PROJECT',
                            'CODEX_HOME',
                            'CLAUDE_CONFIG_DIR',
                            'CLAUDE_CODE_USE_BEDROCK',
                            'AWS_PROFILE',
                            'AWS_REGION',
                            'AWS_DEFAULT_REGION',
                            'AWS_ACCESS_KEY_ID',
                            'AWS_SECRET_ACCESS_KEY',
                            'AWS_SESSION_TOKEN',
                            'GOOGLE_APPLICATION_CREDENTIALS',
                        ],
                        'prefix_allowlist' => [],
                    ],
                ],
            ],
            'redaction' => [
                'extra_patterns' => env('ATLAS_AI_REDACTION_EXTRA_PATTERNS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_REDACTION_EXTRA_PATTERNS'))), fn (string $pattern): bool => $pattern !== ''))
                    : [],
            ],
        ],
        'runtime' => [
            'profile_cache_ttl_seconds' => (int) env('ATLAS_AI_RUNTIME_PROFILE_CACHE_TTL_SECONDS', 300),
            'profile_max_files' => (int) env('ATLAS_AI_RUNTIME_PROFILE_MAX_FILES', 1200),
        ],
        'providers' => [
            'claude_cli' => [
                'binary' => env('ATLAS_AI_CLAUDE_BIN', 'claude'),
                'model' => env('ATLAS_AI_CLAUDE_MODEL', null),
                'model_label' => env('ATLAS_AI_CLAUDE_MODEL_LABEL', env('ATLAS_AI_CLAUDE_MODEL') ?: 'Claude CLI default'),
                'model_tier' => env('ATLAS_AI_CLAUDE_MODEL_TIER', env('ATLAS_AI_DEFAULT_TIER', 'daily')),
                'model_identity' => env('ATLAS_AI_CLAUDE_MODEL_IDENTITY', env('ATLAS_AI_CLAUDE_MODEL') ?: 'claude_cli_default'),
                'fallback_model' => env('ATLAS_AI_CLAUDE_FALLBACK_MODEL', 'claude-haiku-4-5'),
                'fallback_model_label' => env('ATLAS_AI_CLAUDE_FALLBACK_MODEL_LABEL', 'Claude Haiku 4.5'),
                'premium_model' => env('ATLAS_AI_CLAUDE_PREMIUM_MODEL', 'claude-opus-4-7'),
                'premium_model_label' => env('ATLAS_AI_CLAUDE_PREMIUM_MODEL_LABEL', env('ATLAS_AI_CLAUDE_PREMIUM_MODEL') ?: 'Claude Opus 4.7'),
                'allow_auto' => (bool) env('ATLAS_AI_CLAUDE_ALLOW_AUTO', true),
                'allow_manual' => (bool) env('ATLAS_AI_CLAUDE_ALLOW_MANUAL', true),
                'args' => env('ATLAS_AI_CLAUDE_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CLAUDE_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence', '--allowedTools', 'Read'],
            ],
            'codex_cli' => [
                'binary' => env('ATLAS_AI_CODEX_BIN', 'codex'),
                'model' => env('ATLAS_AI_CODEX_MODEL', null),
                'model_label' => env('ATLAS_AI_CODEX_MODEL_LABEL', env('ATLAS_AI_CODEX_MODEL') ?: 'Codex CLI default'),
                'model_tier' => env('ATLAS_AI_CODEX_MODEL_TIER', env('ATLAS_AI_DEFAULT_TIER', 'daily')),
                'model_identity' => env('ATLAS_AI_CODEX_MODEL_IDENTITY', env('ATLAS_AI_CODEX_MODEL') ?: 'codex_cli_default'),
                'fallback_model' => env('ATLAS_AI_CODEX_FALLBACK_MODEL', 'gpt-5.4-mini'),
                'fallback_model_label' => env('ATLAS_AI_CODEX_FALLBACK_MODEL_LABEL', 'GPT-5.4-Mini'),
                'premium_model' => env('ATLAS_AI_CODEX_PREMIUM_MODEL', 'gpt-5.5'),
                'premium_model_label' => env('ATLAS_AI_CODEX_PREMIUM_MODEL_LABEL', env('ATLAS_AI_CODEX_PREMIUM_MODEL') ?: 'GPT-5.5'),
                'allow_auto' => (bool) env('ATLAS_AI_CODEX_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_AI_CODEX_ALLOW_MANUAL', true),
                'sandbox' => env('ATLAS_AI_CODEX_SANDBOX', 'read-only'),
                'args' => env('ATLAS_AI_CODEX_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CODEX_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['exec', '--skip-git-repo-check'],
            ],
            'gemini_cli' => [
                'binary' => env('ATLAS_AI_GEMINI_BIN', 'gemini'),
                'model' => 'gemini-3.1-pro-preview',
                'model_label' => 'Gemini 3.1 Pro Preview',
                'model_tier' => 'premium',
                'model_identity' => 'gemini-3.1-pro-preview',
                'fallback_model' => null,
                'fallback_provider' => env('ATLAS_AI_GEMINI_FALLBACK_PROVIDER', 'claude_cli'),
                'allow_auto' => (bool) env('ATLAS_AI_GEMINI_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_AI_GEMINI_ALLOW_MANUAL', true),
                'home' => env('ATLAS_AI_GEMINI_HOME'),
                'args' => env('ATLAS_AI_GEMINI_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_GEMINI_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : [],
            ],
            'jarvis_mlx' => [
                'binary' => env('ATLAS_AI_JARVIS_MLX_BIN', 'python3'),
                'model' => env('ATLAS_AI_JARVIS_MLX_MODEL', 'jarvis_mlx_default'),
                'model_label' => 'Jarvis MLX Local Engine',
                'model_tier' => 'daily',
                'model_identity' => 'jarvis_mlx_default',
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_AI_JARVIS_MLX_ALLOW_AUTO', true),
                'allow_manual' => (bool) env('ATLAS_AI_JARVIS_MLX_ALLOW_MANUAL', true),
                'args' => [
                    'dissecar/huw-prosser/jarvis-mlx/repo/cli.py',
                ],
            ],
        ],
    ],

    'cli' => [
        'php_binary' => env('ATLAS_PHP_BIN'),
        'php_binary_candidates' => env('ATLAS_PHP_BIN_CANDIDATES')
            ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_PHP_BIN_CANDIDATES'))), fn (string $path): bool => $path !== ''))
            : [
                '/opt/homebrew/bin/php',
                '/opt/homebrew/opt/php/bin/php',
                '/opt/homebrew/opt/php@8.5/bin/php',
                '/opt/homebrew/opt/php@8.4/bin/php',
            ],
        'dogfood_path' => env('ATLAS_CLI_DOGFOOD_PATH') ?: storage_path('app/atlas-cli/dogfood.json'),
        'final_product_doc_paths' => [
            base_path('docs/atlas-cli-final-product.md'),
            dirname(base_path()).'/docs/atlas-cli-final-product.md',
        ],
        'release_checklist_paths' => [
            base_path('docs/atlas-cli-release-checklist.md'),
            dirname(base_path()).'/docs/atlas-cli-release-checklist.md',
        ],
    ],

    // NOTE: Atlas Dev Efficient knobs live in config/atlas_dev.php (canonical
    // source). Reading from `config('atlas.dev.*')` is unsupported and was
    // removed in F-08 cleanup; use `config('atlas_dev.*')` instead.
];
