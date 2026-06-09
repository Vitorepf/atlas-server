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
        'embedding_dimensions' => (int) env('ATLAS_SEMANTIC_EMBEDDING_DIMENSIONS', 384),
        'embedding_provider' => env('ATLAS_SEMANTIC_EMBEDDING_PROVIDER', 'semantic_rag'),
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
        'elevenlabs' => [
            'api_key' => env('ATLAS_ELEVENLABS_API_KEY', env('ELEVENLABS_API_KEY')),
            'voice_id' => env('ATLAS_ELEVENLABS_VOICE_ID'),
            'model_id' => env('ATLAS_ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
            'output_format' => env('ATLAS_ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),
            'timeout_seconds' => (int) env('ATLAS_ELEVENLABS_TIMEOUT_SECONDS', 20),
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

        // Documentation-reality (ADRS) read-model report cache. The report is a
        // pure read-only scan of the canonical docs corpus (~9-10s) that the
        // synchronous create pipeline reaches on every interaction (session
        // bootstrap + feature placement). It is cached cross-request keyed by a
        // stat-only corpus signature (root@sha256(relpath:mtime:size)*), so a real
        // doc add/edit/delete moves the key and recomputes, while repeat requests on
        // an unchanged corpus are served in milliseconds. The TTL is only a safety
        // net on top of the content-keyed invalidation; <=0 disables the cache and
        // recomputes every call (mirrors atlas_vault.structure_cache_seconds for the
        // cartography structure cache).
        'documentation_reality' => [
            'report_cache_seconds' => (int) env('ATLAS_ENGINEERING_DOCUMENTATION_REALITY_REPORT_CACHE_SECONDS', 300),
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
        'default_provider' => env('ATLAS_AI_DEFAULT_PROVIDER', 'hermes_cli'),
        'default_tier' => env('ATLAS_AI_DEFAULT_TIER', 'daily'),
        'council_allow_auto' => (bool) env('ATLAS_AI_COUNCIL_ALLOW_AUTO', false),

        // atlas.ai.trust_ladder — Self-Construction per-change-class friction ladder.
        // DEFAULT = MAX FRICTION (operator approval for everything). `enabled` gates
        // whether the ladder is wired into admission at all; absent thresholds are
        // unreachable (PHP_INT_MAX => the tier never unlocks). A change class earns
        // lower friction ONLY past an operator-set threshold, accruing from re-checkable
        // evidence (frozen-judge pass / clean promotion), and NEVER above the per-risk
        // cap — admission re-binds the earned autonomy under the immutable
        // RISK_TO_MAX_AUTONOMY canon. A single revert resets the class streak to zero.
        // Pinned here so the safe defaults are auditable in canon, not implicit in code.
        'trust_ladder' => [
            'enabled' => (bool) env('ATLAS_TRUST_LADDER_ENABLED', false), // default OFF — unwired
            'thresholds' => [
                // Clean-streak count the operator must set to unlock each tier. Absent
                // => disabled. Uncomment + tune to opt a class in (still capped by risk):
                // 'draft'                 => null,
                // 'execute_with_approval' => null,
                // 'autonomous'            => null,
            ],
        ],

        // atlas.ai.autonomous_learning — "Hermes mode" for self-learning. When ON, the
        // daily atlas:ai:auto-apply-safe consumer auto-approves+applies the SAFE,
        // reversible, NON-sensitive, NON-critical learning classes (memory /
        // retrieval_hint / failure_pattern) with NO per-item approval; everything else
        // stays queued for the Sunday review. DEFAULT OFF — it is a real behavior change.
        // Every application is reversible (archivable AtlasMemoryEntry) and reported in
        // the weekly digest. The pétreo floor (never-merge, never critical/secret/cyber)
        // is enforced fail-closed in AtlasAutonomousLearningApplier, not by this flag.
        'autonomous_learning' => [
            'enabled' => (bool) env('ATLAS_AUTONOMOUS_AUTO_APPLY', false), // default OFF
            'time' => env('ATLAS_AUTONOMOUS_AUTO_APPLY_TIME', '04:10'),
            'limit' => (int) env('ATLAS_AUTONOMOUS_AUTO_APPLY_LIMIT', 50),
        ],

        // atlas.ai.weekly_memory_digest — the Sunday report. READ-ONLY (never mutates),
        // so it defaults ON: every Sunday it reports everything saved to Atlas memory
        // that week + every auto-applied learning, each with a reverse handle.
        'weekly_memory_digest' => [
            'enabled' => (bool) env('ATLAS_WEEKLY_MEMORY_DIGEST', true), // read-only ⇒ default ON
            'time' => env('ATLAS_WEEKLY_MEMORY_DIGEST_TIME', '18:00'),
        ],

        // atlas.ai.capture_quality_gate — stops Atlas from "learning" noise. The gate
        // rejects contentless/empty-template, meta-stub ("a signal was emitted"),
        // fixture/smoke-test echoes, and low-substance candidates, and dedups by CONTENT
        // hash (so identical-content rows collapse instead of multiplying).
        //   mode = off | observe (DEFAULT) | enforce
        //     observe — annotate + log what it WOULD prune; persists everything (no change)
        //     enforce — noise is NOT persisted; identical content collapses
        // Audit any time (read-only): php artisan atlas:ai:capture-quality-audit
        'capture_quality_gate' => [
            'mode' => env('ATLAS_CAPTURE_QUALITY_MODE', 'observe'),
            'min_score' => (int) env('ATLAS_CAPTURE_QUALITY_MIN_SCORE', 20),
        ],

        // POST /ai/interactions runs the synchronous create pipeline (router +
        // placement + documentation-reality / docs-health gates) before enqueueing
        // the trace. On php-fpm that pipeline can exceed the default 30s
        // max_execution_time / 128M memory_limit on a cold request, so the
        // controller lifts BOTH for the create request only (raise-only, never
        // clamping a CLI/test context that already granted more). Mirrors the
        // headroom AtlasCartographyController + AtlasDev/RunController already grant
        // their heavy endpoints. The work is ~10s after the request-scoped report
        // memoization; this ceiling is the safety margin, not the expected runtime.
        'create_max_execution_seconds' => (int) env('ATLAS_AI_CREATE_MAX_EXECUTION_SECONDS', 120),

        // H1 (provider response cache) + H4 (per-operation cost guard).
        // Additive, default-OFF, conservative. When `enabled` is false the
        // AiProviderManager returns every provider undecorated — byte-identical
        // to the pre-cache behavior. When true, CachingAiProvider still caches
        // ONLY provably-deterministic jobs (an explicit payload.cacheable flag,
        // a declared zero temperature, or an allow-listed kind) and is invisible
        // on every MISS / non-cacheable / streaming path.
        'cache' => [
            'enabled' => (bool) env('ATLAS_AI_RESPONSE_CACHE_ENABLED', false),
            // Cache store. Defaults to the application's configured store — which
            // is `file` today (config/cache.php reads CACHE_STORE, default
            // 'file') — so it works WITHOUT Redis, stays local-first/sovereign,
            // survives process restarts. Point at 'database'/'array'/'redis' via
            // ATLAS_AI_RESPONSE_CACHE_STORE with zero code change. Read from env
            // (not config('cache.default')) so this stays safe under
            // `php artisan config:cache`. When null/empty the decorator falls
            // back to the live application default store at call time. The file
            // store has no native atomic compare-and-set: under a burst of
            // identical jobs two real calls may race and both populate the key —
            // acceptable because this is an idempotency/cost optimization (a
            // double-compute of a deterministic job yields the same answer), not
            // a distributed lock. Bounded TTL mitigates the lack of file-store LRU.
            'store' => env('ATLAS_AI_RESPONSE_CACHE_STORE', env('CACHE_STORE', 'file')),
            'key_prefix' => env('ATLAS_AI_RESPONSE_CACHE_PREFIX', 'atlas:ai:response_cache:'),
            'ttl_seconds' => (int) env('ATLAS_AI_RESPONSE_CACHE_TTL_SECONDS', 3600),
            // Hard upper bound on TTL so a stale deterministic answer cannot live
            // forever (and a per-job payload.cache_ttl_seconds override is clamped
            // to this).
            'max_ttl_seconds' => (int) env('ATLAS_AI_RESPONSE_CACHE_MAX_TTL_SECONDS', 86400),
            'record_outcomes' => (bool) env('ATLAS_AI_RESPONSE_CACHE_RECORD_OUTCOMES', true),
            // Allow-list of provably-deterministic $job->kind values (default
            // empty). NEVER a deny-list: kind is assigned freely across the
            // codebase, so it can only ever opt specific kinds IN.
            'cacheable_kinds' => array_values(array_filter(
                explode(',', (string) env('ATLAS_AI_RESPONSE_CACHE_KINDS', '')),
                static fn (string $k): bool => trim($k) !== '',
            )),
            // Per-operation cost guard (H4). Units are token-economy normalized
            // units (AtlasTokenEconomyBudgetPolicyService::estimateCost). Both
            // default to 0 = DISABLED (no soft warn, no hard gate), so the guard
            // is a no-op until the operator sets thresholds. Soft is a >=
            // telemetry warning; hard is a strict > refusal (throws before any
            // provider spend), composing UNDER the loop-level STATUS_BUDGET stop.
            'cost_guard' => [
                'soft_units' => (float) env('ATLAS_AI_CALL_COST_GUARD_SOFT_UNITS', 0),
                'hard_units' => (float) env('ATLAS_AI_CALL_COST_GUARD_HARD_UNITS', 0),
            ],
        ],

        // Hermes Executive Runtime auto-routing gate (default-safe).
        // Atlas Decide may auto-route to hermes_cli ONLY when providers.hermes_cli.allow_auto
        // is true AND the task matches this allowlist AND no privacy/memory/gateway block applies.
        'hermes_runtime_router' => [
            'compatible_tasks' => ['ops', 'gateway', 'long_running', 'tool_heavy', 'research'],
            'compatible_domains' => ['ops', 'gateway', 'research', 'programming'],
            'allow_coding_when_advantageous' => (bool) env('ATLAS_AI_HERMES_ROUTER_ALLOW_CODING', false),
            'fallback_provider' => env('ATLAS_AI_HERMES_ROUTER_FALLBACK', 'claude_cli'),
        ],
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
                'hermes_cli' => [
                    'max_visible_tokens' => env('ATLAS_AI_HERMES_BUDGET_MAX_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_HERMES_BUDGET_MAX_VISIBLE_TOKENS') : null,
                    'warn_visible_tokens' => env('ATLAS_AI_HERMES_BUDGET_WARN_VISIBLE_TOKENS') !== null ? (int) env('ATLAS_AI_HERMES_BUDGET_WARN_VISIBLE_TOKENS') : null,
                ],
            ],
        ],
        'default_agent' => env('ATLAS_AI_DEFAULT_AGENT', 'orquestrador'),
        // Best-effort persistent-context enrichment on the SYNCHRONOUS interaction
        // create path. It runs the heavy session bootstrap (which fans out into
        // hundreds of LIKE seq-scans over atlas_engineering_code_symbols) and, under
        // load, exceeds PHP's max_execution_time and fatals the create. OFF by
        // default so create stays fast/reliable — the worker still runs the mission.
        // Re-enable once the bootstrap code-symbol search is batched/cached.
        'persistent_context' => [
            'gateway_enabled' => (bool) env('ATLAS_AI_PERSISTENT_CONTEXT_GATEWAY_ENABLED', false),
        ],
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
                            'HERMES_HOME',
                            'HERMES_CONFIG',
                            'HERMES_ACCEPT_HOOKS',
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
                            'HERMES_HOME',
                            'HERMES_CONFIG',
                            'HERMES_ACCEPT_HOOKS',
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
        'compute_effort' => [
            'default' => env('ATLAS_AI_COMPUTE_EFFORT_DEFAULT', 'balanced'),
            'levels' => ['fast', 'balanced', 'deep', 'max'],
            'measurement_schema' => 'atlas.compute_effort_signal.v1',
        ],
        'providers' => [
            'claude_cli' => [
                'binary' => env('ATLAS_AI_CLAUDE_BIN', 'claude'),
                'model' => env('ATLAS_AI_CLAUDE_MODEL', 'claude-sonnet-4-6'),
                'model_label' => env('ATLAS_AI_CLAUDE_MODEL_LABEL', env('ATLAS_AI_CLAUDE_MODEL') ?: 'Claude Sonnet 4.6'),
                'model_tier' => env('ATLAS_AI_CLAUDE_MODEL_TIER', env('ATLAS_AI_DEFAULT_TIER', 'daily')),
                'model_identity' => env('ATLAS_AI_CLAUDE_MODEL_IDENTITY', env('ATLAS_AI_CLAUDE_MODEL') ?: 'claude-sonnet-4-6'),
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
                'model' => env('ATLAS_AI_CODEX_MODEL', 'gpt-5.3-codex-spark'),
                'model_label' => env('ATLAS_AI_CODEX_MODEL_LABEL', env('ATLAS_AI_CODEX_MODEL') ?: 'GPT-5.3-Codex-Spark'),
                'model_tier' => env('ATLAS_AI_CODEX_MODEL_TIER', env('ATLAS_AI_DEFAULT_TIER', 'daily')),
                'model_identity' => env('ATLAS_AI_CODEX_MODEL_IDENTITY', env('ATLAS_AI_CODEX_MODEL') ?: 'gpt-5.3-codex-spark'),
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
                'default_model_alias' => env('ATLAS_AI_GEMINI_DEFAULT_MODEL_ALIAS', 'gemini_flash'),
                'model' => env('ATLAS_AI_GEMINI_MODEL', env('ATLAS_AI_GEMINI_FLASH_MODEL', 'gemini-3.5-flash')),
                'model_label' => env('ATLAS_AI_GEMINI_MODEL_LABEL', env('ATLAS_AI_GEMINI_FLASH_LABEL', 'Gemini Flash')),
                'model_tier' => env('ATLAS_AI_GEMINI_MODEL_TIER', env('ATLAS_AI_GEMINI_FLASH_TIER', 'daily')),
                'model_identity' => env('ATLAS_AI_GEMINI_MODEL_IDENTITY', env('ATLAS_AI_GEMINI_FLASH_MODEL', 'gemini-3.5-flash')),
                'model_family' => 'gemini',
                'models' => [
                    'gemini_flash' => [
                        'model' => env('ATLAS_AI_GEMINI_FLASH_MODEL', env('ATLAS_AI_GEMINI_MODEL', 'gemini-3.5-flash')),
                        'label' => env('ATLAS_AI_GEMINI_FLASH_LABEL', 'Gemini 3.5 Flash'),
                        'tier' => env('ATLAS_AI_GEMINI_FLASH_TIER', 'daily'),
                        'family' => 'gemini',
                    ],
                    'gemini_pro' => [
                        'model' => env('ATLAS_AI_GEMINI_PRO_MODEL', 'gemini-3.1-pro-preview'),
                        'label' => env('ATLAS_AI_GEMINI_PRO_LABEL', 'Gemini 3.1 Pro'),
                        'tier' => env('ATLAS_AI_GEMINI_PRO_TIER', 'premium'),
                        'family' => 'gemini',
                    ],
                ],
                'fallback_model' => null,
                'fallback_provider' => env('ATLAS_AI_GEMINI_FALLBACK_PROVIDER', 'claude_cli'),
                'allow_auto' => (bool) env('ATLAS_AI_GEMINI_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_AI_GEMINI_ALLOW_MANUAL', true),
                'home' => env('ATLAS_AI_GEMINI_HOME'),
                'args' => env('ATLAS_AI_GEMINI_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_GEMINI_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : [],
            ],
            'antigravity_sdk' => [
                'enabled' => (bool) env('ATLAS_ANTIGRAVITY_SDK_ENABLED', false),
                'python' => env('ATLAS_ANTIGRAVITY_SDK_PYTHON', 'python3'),
                'module' => env('ATLAS_ANTIGRAVITY_SDK_MODULE', 'google.antigravity'),
                'adapter_path' => env('ATLAS_ANTIGRAVITY_SDK_ADAPTER_PATH', 'runtimes/python/antigravity_sdk/adapter.py'),
                'timeout_seconds' => (int) env('ATLAS_ANTIGRAVITY_SDK_TIMEOUT', 120),
                'max_output_chars' => (int) env('ATLAS_ANTIGRAVITY_SDK_MAX_OUTPUT_CHARS', 12000),
                'model' => env('ATLAS_ANTIGRAVITY_SDK_MODEL', 'selected-by-atlas-decide'),
                'model_label' => env('ATLAS_ANTIGRAVITY_SDK_MODEL_LABEL', 'Antigravity SDK selected by Atlas Decide'),
                'model_tier' => env('ATLAS_ANTIGRAVITY_SDK_MODEL_TIER', 'experimental'),
                'model_identity' => env('ATLAS_ANTIGRAVITY_SDK_MODEL_IDENTITY', 'antigravity_sdk_selected_by_atlas_decide'),
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_ANTIGRAVITY_SDK_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_ANTIGRAVITY_SDK_ALLOW_MANUAL', false),
                'auth_env' => ['ANTIGRAVITY_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'],
            ],
            'cursor_sdk' => [
                'enabled' => (bool) env('ATLAS_CURSOR_SDK_ENABLED', false),
                'node' => env('ATLAS_CURSOR_SDK_NODE', 'node'),
                'module' => env('ATLAS_CURSOR_SDK_MODULE', '@cursor/sdk'),
                'adapter_path' => env('ATLAS_CURSOR_SDK_ADAPTER_PATH', 'runtimes/node/cursor_sdk/adapter.mjs'),
                'runtime_mode' => env('ATLAS_CURSOR_SDK_RUNTIME_MODE', 'local'),
                'sandbox_enabled' => (bool) env('ATLAS_CURSOR_SDK_SANDBOX_ENABLED', true),
                'setting_sources' => ['project'],
                'timeout_seconds' => (int) env('ATLAS_CURSOR_SDK_TIMEOUT', 120),
                'max_output_chars' => (int) env('ATLAS_CURSOR_SDK_MAX_OUTPUT_CHARS', 12000),
                'model' => env('ATLAS_CURSOR_SDK_MODEL', 'selected-by-atlas-decide'),
                'model_label' => env('ATLAS_CURSOR_SDK_MODEL_LABEL', 'Cursor SDK selected by Atlas Decide'),
                'model_tier' => env('ATLAS_CURSOR_SDK_MODEL_TIER', 'experimental'),
                'model_identity' => env('ATLAS_CURSOR_SDK_MODEL_IDENTITY', 'cursor_sdk_selected_by_atlas_decide'),
                'billing_mode' => env('ATLAS_CURSOR_SDK_BILLING_MODE', 'cursor_account_usage_bucket'),
                'quota_bucket' => env('ATLAS_CURSOR_SDK_QUOTA_BUCKET', 'cursor_account_default'),
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_CURSOR_SDK_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_CURSOR_SDK_ALLOW_MANUAL', false),
                'auth_env' => ['CURSOR_API_KEY'],
            ],
            'cursor_cli' => [
                'enabled' => (bool) env('ATLAS_CURSOR_CLI_ENABLED', false),
                'binary' => env('ATLAS_CURSOR_CLI_BINARY', 'cursor-agent'),
                'binary_candidates' => env('ATLAS_CURSOR_CLI_BINARY_CANDIDATES')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_CURSOR_CLI_BINARY_CANDIDATES'))), fn (string $path): bool => $path !== ''))
                    : ['cursor-agent'],
                'auth_mode' => env('ATLAS_CURSOR_CLI_AUTH_MODE', 'local_login'),
                'auth_env' => ['CURSOR_API_KEY'],
                'output_format' => env('ATLAS_CURSOR_CLI_OUTPUT_FORMAT', 'stream-json'),
                'force' => (bool) env('ATLAS_CURSOR_CLI_FORCE', false),
                'timeout_seconds' => (int) env('ATLAS_CURSOR_CLI_TIMEOUT', 300),
                'max_output_chars' => (int) env('ATLAS_CURSOR_CLI_MAX_OUTPUT_CHARS', 12000),
                'model' => env('ATLAS_CURSOR_CLI_MODEL', 'composer-2.5-fast'),
                'model_label' => env('ATLAS_CURSOR_CLI_MODEL_LABEL', 'Cursor CLI Composer/Agent'),
                'composer_2_5_model' => env('ATLAS_CURSOR_CLI_COMPOSER_2_5_MODEL', 'composer-2.5'),
                'composer_2_5_model_label' => env('ATLAS_CURSOR_CLI_COMPOSER_2_5_MODEL_LABEL', 'Composer 2.5'),
                'aliases' => env('ATLAS_CURSOR_CLI_ALIASES'),
                'composer_2_5_aliases' => env('ATLAS_CURSOR_CLI_COMPOSER_2_5_ALIASES'),
                'model_tier' => env('ATLAS_CURSOR_CLI_MODEL_TIER', 'subsidized_account'),
                'model_identity' => env('ATLAS_CURSOR_CLI_MODEL_IDENTITY', 'cursor_cli_composer_account_pool'),
                'billing_mode' => env('ATLAS_CURSOR_CLI_BILLING_MODE', 'cursor_account_cli_pool'),
                'quota_bucket' => env('ATLAS_CURSOR_CLI_QUOTA_BUCKET', 'cursor_account_composer_pool'),
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_CURSOR_CLI_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_CURSOR_CLI_ALLOW_MANUAL', false),
            ],
            'minimax_m27' => [
                'enabled' => (bool) env('ATLAS_MINIMAX_ENABLED', false),
                'auth_mode' => env('ATLAS_MINIMAX_AUTH_MODE', 'token_plan_key'),
                'token_plan_key' => env('ATLAS_MINIMAX_TOKEN_PLAN_KEY'),
                'paygo_enabled' => (bool) env('ATLAS_MINIMAX_PAYGO_ENABLED', false),
                'paygo_api_key' => env('ATLAS_MINIMAX_PAYGO_API_KEY'),
                'model' => env('ATLAS_MINIMAX_MODEL', 'MiniMax-M3'),
                'allow_highspeed' => (bool) env('ATLAS_MINIMAX_ALLOW_HIGHSPEED', false),
                'base_url' => env('ATLAS_MINIMAX_BASE_URL', 'https://api.minimax.io'),
                'timeout_seconds' => (int) env('ATLAS_MINIMAX_TIMEOUT_SECONDS', 120),
                'max_output_tokens' => (int) env('ATLAS_MINIMAX_MAX_OUTPUT_TOKENS', 8192),
                'request_budget_per_window' => env('ATLAS_MINIMAX_REQUEST_BUDGET_PER_WINDOW'),
                'credits_overflow_enabled' => (bool) env('ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED', false),
                'model_identity' => 'minimax_m27_token_plan',
                'billing_mode' => 'token_plan_request_based',
                'model_tier' => 'token_plan_subsidized',
                'allow_auto' => (bool) env('ATLAS_MINIMAX_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_MINIMAX_ALLOW_MANUAL', false),
            ],
            'minimax_m27_cli' => [
                'enabled' => (bool) env('ATLAS_MINIMAX_CLI_ENABLED', false),
                'python' => env('ATLAS_MINIMAX_CLI_PYTHON', 'python3'),
                'adapter_path' => env('ATLAS_MINIMAX_CLI_ADAPTER_PATH', 'runtimes/python/minimax_m27/adapter.py'),
                'auth_mode' => env('ATLAS_MINIMAX_CLI_AUTH_MODE', 'token_plan_key'),
                'token_plan_key' => env('ATLAS_MINIMAX_TOKEN_PLAN_KEY'),
                'paygo_enabled' => (bool) env('ATLAS_MINIMAX_PAYGO_ENABLED', false),
                'paygo_api_key' => env('ATLAS_MINIMAX_PAYGO_API_KEY'),
                'model' => env('ATLAS_MINIMAX_MODEL', 'MiniMax-M3'),
                'allow_highspeed' => (bool) env('ATLAS_MINIMAX_ALLOW_HIGHSPEED', false),
                'base_url' => env('ATLAS_MINIMAX_BASE_URL', 'https://api.minimax.io'),
                'timeout_seconds' => (int) env('ATLAS_MINIMAX_TIMEOUT_SECONDS', 120),
                'max_output_chars' => (int) env('ATLAS_MINIMAX_CLI_MAX_OUTPUT_CHARS', 12000),
                'model_identity' => 'minimax_m27_cli_token_plan',
                'billing_mode' => 'token_plan_request_based',
                'model_tier' => 'token_plan_subsidized',
                'allow_auto' => (bool) env('ATLAS_MINIMAX_CLI_ALLOW_AUTO', false),
                'allow_manual' => (bool) env('ATLAS_MINIMAX_CLI_ALLOW_MANUAL', true),
            ],
            'hermes_cli' => [
                'binary' => env('ATLAS_AI_HERMES_BIN', 'hermes'),
                // Execution transport: 'acp' = persistent `hermes acp` JSON-RPC session
                // (robust: warm, structured, no stdout parsing, no checkpoints/workdir
                // hang); 'cli' = per-call `hermes chat` subprocess (fallback). Default
                // 'acp' (proven: 15/15 real creates on a current worker, 0 fallback);
                // ACP auto-falls back to CLI on any transport failure regardless.
                'execution_transport' => env('ATLAS_AI_HERMES_EXECUTION_TRANSPORT', 'acp'),
                // Warm ACP session pool: reuse ONE persistent `hermes acp` process per
                // worker across jobs (only the first job pays the ~5s cold start: proc
                // spawn + initialize + MCP registration). Each job still gets a fresh
                // session/new (no shared context); a session is reused only after a
                // clean success and recycled after `max_prompts`. Default-on; disable to
                // cold-start a fresh ACP process per call (still ACP, just no reuse).
                'acp_warm_pool' => (bool) env('ATLAS_AI_HERMES_ACP_WARM_POOL', true),
                'acp_warm_pool_max_prompts' => (int) env('ATLAS_AI_HERMES_ACP_WARM_POOL_MAX_PROMPTS', 50),
                'model' => env('ATLAS_AI_HERMES_MODEL', 'hermes_cli_default'),
                'model_label' => env('ATLAS_AI_HERMES_MODEL_LABEL', env('ATLAS_AI_HERMES_MODEL') ?: 'Hermes Executive Runtime'),
                'model_tier' => env('ATLAS_AI_HERMES_MODEL_TIER', 'executive_runtime'),
                'model_identity' => env('ATLAS_AI_HERMES_MODEL_IDENTITY', env('ATLAS_AI_HERMES_MODEL') ?: 'hermes_cli_default'),
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_AI_HERMES_ALLOW_AUTO', true),
                'allow_manual' => (bool) env('ATLAS_AI_HERMES_ALLOW_MANUAL', true),
                'provider' => env('ATLAS_AI_HERMES_PROVIDER'),
                'toolsets' => env('ATLAS_AI_HERMES_TOOLSETS'),
                'skills' => env('ATLAS_AI_HERMES_SKILLS'),
                'source' => env('ATLAS_AI_HERMES_SOURCE', 'tool'),
                'max_turns' => (int) env('ATLAS_AI_HERMES_MAX_TURNS', 90),
                'timeout_seconds' => (int) env('ATLAS_AI_HERMES_TIMEOUT_SECONDS', env('ATLAS_AI_TIMEOUT_SECONDS', 600)),
                'memory_policy' => env('ATLAS_AI_HERMES_MEMORY_POLICY', 'off'),
                'schedule_policy' => env('ATLAS_AI_HERMES_SCHEDULE_POLICY', 'off'),
                'procedure_policy' => env('ATLAS_AI_HERMES_PROCEDURE_POLICY', 'off'),
                'capability_policy' => [
                    'enabled' => (bool) env('ATLAS_AI_HERMES_CAPABILITY_POLICY_ENABLED', false),
                    'allow' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_HERMES_CAPABILITY_ALLOW', ''))), fn (string $id): bool => $id !== '')),
                    'allow_by_mode' => [],
                    'always_quarantine_classes' => ['mcp_server', 'hook', 'delegation', 'code_exec', 'gateway'],
                    'config_yaml_path' => env('ATLAS_AI_HERMES_CONFIG_YAML', '~/.hermes/config.yaml'),
                    'probe_timeout_seconds' => (int) env('ATLAS_AI_HERMES_PROBE_TIMEOUT', 15),
                ],
                'capability_probe_schedule_enabled' => (bool) env('ATLAS_AI_HERMES_CAPABILITY_PROBE_SCHEDULE', false),
                'mcp_policy' => env('ATLAS_AI_HERMES_MCP_POLICY', 'off'),
                'mcp' => [
                    'allowed_servers' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_HERMES_MCP_ALLOWED_SERVERS', ''))), fn (string $s): bool => $s !== '')),
                    'managed_config_dir' => storage_path('app/hermes/mcp'),
                ],
                'delegation_policy' => env('ATLAS_AI_HERMES_DELEGATION_POLICY', 'off'),
                'delegation_max_concurrent_children' => (int) env('ATLAS_AI_HERMES_DELEGATION_MAX_CONCURRENT', 3),
                'delegation_spawn_depth_ceiling' => (int) env('ATLAS_AI_HERMES_DELEGATION_DEPTH_CEILING', 1),
                'delegation_child_timeout_seconds_max' => (int) env('ATLAS_AI_HERMES_DELEGATION_CHILD_TIMEOUT_MAX', 600),
                'delegation_max_iterations_max' => (int) env('ATLAS_AI_HERMES_DELEGATION_MAX_ITERATIONS_MAX', 50),
                // Executive Mesh: governed many-agent fan-out (default-off). The
                // mesh only dispatches when policy === 'atlas_adapter'. Raise the
                // worker/children ceilings to use "numbers" of agents; the leaf
                // block + delegation caps stay binding regardless.
                'mesh' => [
                    'policy' => env('ATLAS_AI_HERMES_MESH_POLICY', 'off'),
                    // AUTO-ROUTE (default-off, SECOND consent on top of `policy`): when
                    // true, AtlasDecide may auto-route an advised mission (mesh.policy
                    // =atlas_adapter + a per-request decomposition signal + privacy ok)
                    // to a live governed fleet via the create→worker pipeline. Kept
                    // separate from `policy` so enabling manual `atlas:hermes:mesh
                    // dispatch` never silently lets Decide spawn fleets on its own.
                    'auto_route' => (bool) env('ATLAS_AI_HERMES_MESH_AUTO_ROUTE', false),
                    'max_parallel_workers' => (int) env('ATLAS_AI_HERMES_MESH_MAX_PARALLEL', 8),
                    'max_children' => (int) env('ATLAS_AI_HERMES_MESH_MAX_CHILDREN', 64),
                    'checkpoint_policy' => env('ATLAS_AI_HERMES_MESH_CHECKPOINT_POLICY', 'off'),
                    'worktree_fleet' => (bool) env('ATLAS_AI_HERMES_MESH_WORKTREE_FLEET', true),
                    // OPT-IN: relocate each child to a managed per-profile HERMES_HOME.
                    // Default false so children inherit the operator's real ~/.hermes
                    // (model/provider/auth). Only enable with profiles that set their
                    // own provider/model, else the child cannot resolve a model.
                    'isolate_profile_home' => (bool) env('ATLAS_AI_HERMES_MESH_ISOLATE_PROFILE_HOME', false),
                    // role => ['toolsets'=>[...],'provider'=>?,'model'=>?,'skills'=>[...]]
                    'profiles' => [],
                    'poll_interval_microseconds' => (int) env('ATLAS_AI_HERMES_MESH_POLL_US', 50000),
                ],
                // Hermes Kanban swarm substrate (DURABLE workers→verifier→synthesizer
                // graph), distinct from the EPHEMERAL Executive Mesh fan-out above:
                // Atlas drives `hermes kanban swarm`/`dispatch` ONE-SHOT against a
                // per-mission Atlas-OWNED ephemeral board slug it creates and deletes
                // (no Hermes daemon, no persistent Hermes board — sovereignty-safe).
                // Default-off + fail-closed: live dispatch needs policy=atlas_adapter
                // AND an explicit confirm; everything else is plan/dry-run (no spawn).
                'kanban' => [
                    'policy' => env('ATLAS_AI_HERMES_KANBAN_POLICY', 'off'),
                    'board_prefix' => env('ATLAS_AI_HERMES_KANBAN_BOARD_PREFIX', 'atlas-mission'),
                    'max_workers' => (int) env('ATLAS_AI_HERMES_KANBAN_MAX_WORKERS', 8),
                    'max_dispatch_passes' => (int) env('ATLAS_AI_HERMES_KANBAN_MAX_DISPATCH_PASSES', 40),
                    'max_spawns_per_pass' => (int) env('ATLAS_AI_HERMES_KANBAN_MAX_SPAWNS_PER_PASS', 4),
                    'per_task_max_runtime_seconds' => (int) env('ATLAS_AI_HERMES_KANBAN_PER_TASK_MAX_RUNTIME', 1800),
                    'dispatch_poll_microseconds' => (int) env('ATLAS_AI_HERMES_KANBAN_POLL_US', 1000000),
                    'delete_board_after_run' => (bool) env('ATLAS_AI_HERMES_KANBAN_DELETE_BOARD_AFTER_RUN', true),
                    // FORGE consumer (default-off, THIRD consent on top of policy +
                    // confirm): when true, Forge/Mission may dispatch a decomposed obra
                    // as a durable kanban swarm via ForgeKanbanSwarmDispatcher. Kept
                    // separate from `policy` so enabling the substrate never silently
                    // lets Forge route campaigns into it.
                    'dispatch_for_forge' => (bool) env('ATLAS_AI_HERMES_KANBAN_DISPATCH_FOR_FORGE', false),
                    'forge_verifier_profile' => env('ATLAS_AI_HERMES_KANBAN_FORGE_VERIFIER', 'verifier'),
                    'forge_synthesizer_profile' => env('ATLAS_AI_HERMES_KANBAN_FORGE_SYNTHESIZER', 'synthesizer'),
                ],
                'session_evidence_policy' => env('ATLAS_AI_HERMES_SESSION_EVIDENCE_POLICY', 'off'),
                'skill_provision_policy' => env('ATLAS_AI_HERMES_SKILL_PROVISION_POLICY', 'off'),
                'skills_external_dir' => env('ATLAS_AI_HERMES_SKILLS_EXTERNAL_DIR', storage_path('app/atlas/hermes-skills')),
                'skills_hub_ingest' => (bool) env('ATLAS_AI_HERMES_SKILLS_HUB_INGEST', false),
                'hook_policy' => env('ATLAS_AI_HERMES_HOOK_POLICY', 'off'),
                'hook_interception' => (bool) env('ATLAS_AI_HERMES_HOOK_INTERCEPTION', false),
                'hook_sink_host' => env('ATLAS_AI_HERMES_HOOK_SINK_HOST', '127.0.0.1'),
                'hook_sink_base_url' => env('ATLAS_AI_HERMES_HOOK_SINK_URL'),
                'hermes_home' => env('ATLAS_AI_HERMES_HOME'),
                'worktree' => (bool) env('ATLAS_AI_HERMES_WORKTREE', false),
                'accept_hooks' => (bool) env('ATLAS_AI_HERMES_ACCEPT_HOOKS', true),
                // DEFAULT OFF: `--checkpoints` snapshots the ENTIRE working dir before
                // edits. The default workdir is dirname(base_path()) (~11GB), so a
                // snapshot per chat turn blew past the 120s UI budget and made the
                // Hermes chat path appear "broken". Opt-in only for scoped code
                // missions running in a small worktree.
                'checkpoints' => (bool) env('ATLAS_AI_HERMES_CHECKPOINTS', false),
                'args' => env('ATLAS_AI_HERMES_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_HERMES_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['chat', '--quiet'],
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

    // Atlas Cognition Operating System (ACOS) — toggles e budgets canonicos.
    // Doc canon: docs/engineering-knowledge-base/atlas-cognition-operating-system.md.
    'cognition' => [
        // Absorcao 1 (mem0): Integer ID Mapping anti-halucinacao.
        // Doc: atlas-external-memory-pattern-absorptions-v1.md (Absorcao 1).
        // Quando habilitado, AiContextPackBuilder remapeia UUIDs em prompts.
        // Default false ate round-trip tests + integracao verificados em producao.
        'id_remap' => [
            'enabled' => (bool) env('ATLAS_COGNITION_ID_REMAP_ENABLED', false),
            'ttl_seconds' => (int) env('ATLAS_COGNITION_ID_REMAP_TTL_SECONDS', 3600),
            // Bracket style do label provider-safe. Fixo "square_bracket" em v1.
            'bracket_style' => env('ATLAS_COGNITION_ID_REMAP_BRACKET_STYLE', 'square_bracket'),
        ],
    ],

    // Patamar 4 · runtime flags. All default OFF except cron heartbeat and
    // ensure-launchd self-heal. Operator activates production via
    // `php artisan atlas:patamar4:activate-flags --apply`.
    'patamar4' => [
        'scheduler_heartbeat_enabled' => (bool) env('ATLAS_PATAMAR4_SCHEDULER_HEARTBEAT_ENABLED', true),
        'scheduler_ensure_launchd_enabled' => (bool) env('ATLAS_PATAMAR4_SCHEDULER_ENSURE_LAUNCHD_ENABLED', true),
        'reconciliation_enabled' => (bool) env('ATLAS_PATAMAR4_RECONCILIATION_ENABLED', true),
        'reconciliation_cadence' => env('ATLAS_PATAMAR4_RECONCILIATION_CADENCE', 'fifteen'),
        'nightly_counterfactuals_enabled' => (bool) env('ATLAS_PATAMAR4_NIGHTLY_COUNTERFACTUALS_ENABLED', true),
        'adml_sweep_enabled' => (bool) env('ATLAS_PATAMAR4_ADML_SWEEP_ENABLED', true),
        'swarm_production_resolver_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_PRODUCTION_RESOLVER_ENABLED', false),
        'swarm_parallel_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_PARALLEL_ENABLED', false),
        'swarm_auto_failover_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_AUTO_FAILOVER_ENABLED', false),
        'swarm_circuit_threshold' => (int) env('ATLAS_PATAMAR4_SWARM_CIRCUIT_THRESHOLD', 3),
        'swarm_circuit_cooldown_seconds' => (int) env('ATLAS_PATAMAR4_SWARM_CIRCUIT_COOLDOWN_SECONDS', 60),
        'runtime_degradation_auto_tick_enabled' => (bool) env('ATLAS_PATAMAR4_RUNTIME_DEGRADATION_AUTO_TICK_ENABLED', true),
        'runtime_degradation_auto_tick_threshold' => env('ATLAS_PATAMAR4_RUNTIME_DEGRADATION_AUTO_TICK_THRESHOLD', 'high'),
    ],

    // Forge product cockpit execution wiring. DEFAULT OFF: the product cockpit
    // route POST /atlas-code/works/{project}/forge/live-executions historically
    // runs the deterministic fixture (AtlasForgeLiveExecutionService). When ON,
    // the cockpit routes through the REAL governed chain — AtlasForgeRuntimeDispatchService
    // (Atlas Decide picks the provider + live_atlas_decide Decision Receipt) ->
    // AtlasForgeProviderInvocationService (13 gates + operator confirmations) ->
    // real driver. Dark-launched + instantly revertible; flip only after the
    // cockpit->Decide->Dispatch->Invocation wiring is proven (atlas-local first).
    'forge' => [
        'cockpit_real_invocation_enabled' => (bool) env('ATLAS_FORGE_COCKPIT_REAL_INVOCATION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Evolution Loop (the autonomous self-improvement loop)
    |--------------------------------------------------------------------------
    | Provider-agnostic by construction. `default_provider` INHERITS the global
    | Atlas default and is swappable in one line / one env var — the loop never
    | names a provider in code, so removing any provider (Hermes included) does
    | not break it; the loop falls back to whatever this resolves to (or to the
    | senior-loop's own config default / Atlas Decide when empty).
    */
    'loop' => [
        // '' (empty) => let the senior-loop / Atlas Decide pick. Set a key to pin.
        'default_provider' => (string) env('ATLAS_LOOP_DEFAULT_PROVIDER', env('ATLAS_AI_DEFAULT_PROVIDER', '')),
        // Baseline / minimum candidate scenarios the loop explores per task before
        // the frozen judge picks the best (the "explore 20, keep the 1 that works").
        'scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_SCENARIOS_PER_TASK', 3)),
        // DEEP SEARCH: the loop keeps exploring NEW scenarios (up to this hard cap)
        // as long as it keeps finding strictly-better candidates — it spends real
        // time hunting the best evolution scenario, like a junior exploring 20
        // options until the right one. It stops early once it converges (below).
        'max_scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_MAX_SCENARIOS_PER_TASK', 12)),
        // Convergence: once a winner exists, stop after this many consecutive
        // scenarios that fail to improve it (patience). Higher = searches harder.
        'search_patience' => max(1, (int) env('ATLAS_LOOP_SEARCH_PATIENCE', 3)),
        // Hard caps per task (the autoresearch fixed-budget discipline).
        'max_seconds_per_scenario' => max(30, (int) env('ATLAS_LOOP_MAX_SECONDS_PER_SCENARIO', 600)),
        // The loop NEVER merges to main: it accumulates certified-for-review proposals.
        'propose_only' => (bool) env('ATLAS_LOOP_PROPOSE_ONLY', true),

        // The 24h CAMPAIGN runtime — the durable supervisor around the per-task engine.
        // It self-feeds (discovery + generator refill the queue), grinds in parallel
        // workers, persists proposals, loops back, and survives crashes via leases.
        'campaign' => [
            // Parallel grind workers (the "orquestração de agentes"). cpu-aware ceiling
            // applied at runtime; this is the requested width.
            'workers' => max(1, (int) env('ATLAS_LOOP_WORKERS', 3)),
            // Refill the queue when pending tasks fall below this (keeps 24h fed).
            'queue_low_watermark' => max(1, (int) env('ATLAS_LOOP_QUEUE_LOW_WATERMARK', 4)),
            // Targets discovered + seeded per refill wave.
            'refill_batch' => max(1, (int) env('ATLAS_LOOP_REFILL_BATCH', 6)),
            // Default wall-clock budget for a campaign (seconds). 0 = no cap. Default 24h.
            'max_seconds' => max(0, (int) env('ATLAS_LOOP_CAMPAIGN_MAX_SECONDS', 86400)),
            // Optional hard caps (0/empty => unbounded; budget is then time-only).
            'max_proposals' => (int) env('ATLAS_LOOP_CAMPAIGN_MAX_PROPOSALS', 0),
            'max_tasks' => (int) env('ATLAS_LOOP_CAMPAIGN_MAX_TASKS', 0),
            // Per-task claim lease: a crashed worker's task is reclaimed after this.
            'task_lease_seconds' => max(60, (int) env('ATLAS_LOOP_TASK_LEASE_SECONDS', 1800)),
            // Campaign exclusive lock lease: a crashed supervisor's campaign is resumable after this.
            'lock_lease_seconds' => max(60, (int) env('ATLAS_LOOP_LOCK_LEASE_SECONDS', 3600)),
            // Supervisor heartbeat cadence (seconds).
            'heartbeat_seconds' => max(5, (int) env('ATLAS_LOOP_HEARTBEAT_SECONDS', 30)),
            // Operator control files — touch to gracefully pause / kill a running campaign.
            'kill_switch_file' => (string) env('ATLAS_LOOP_KILL_SWITCH', storage_path('atlas-loop/KILL')),
            'pause_switch_file' => (string) env('ATLAS_LOOP_PAUSE_SWITCH', storage_path('atlas-loop/PAUSE')),
            // Isolated checkout the grind runs against (never the operator's working tree).
            // '' => derive a dedicated sibling worktree at runtime.
            'worktree_path' => (string) env('ATLAS_LOOP_WORKTREE', ''),
            // Repo subtrees discovery researches for high-value, self-contained targets.
            'discovery_roots' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('ATLAS_LOOP_DISCOVERY_ROOTS', 'app/Services,app/Support,app/Models'))
            ), static fn (string $p): bool => $p !== '')),
            // Per-attempt hard wall-clock kill (the TimeBoundedLoopExecutionDriver deadline):
            // one hung provider call can never wedge the 24h run.
            'attempt_hard_seconds' => max(30, (int) env('ATLAS_LOOP_ATTEMPT_HARD_SECONDS', 900)),
            // Disk governor: refuse a new scenario below this free-MB floor; reap scenario
            // workspaces older than the TTL (catches the crash path the happy-path cleanup can't).
            'min_free_mb' => max(0, (int) env('ATLAS_LOOP_MIN_FREE_MB', 512)),
            'max_live_workspaces' => max(0, (int) env('ATLAS_LOOP_MAX_LIVE_WORKSPACES', 0)), // 0 = no cap
            'orphan_ttl_seconds' => max(60, (int) env('ATLAS_LOOP_ORPHAN_TTL_SECONDS', 1800)),
            // Rate limit between cycles (0 = no sleep).
            'sleep_seconds' => max(0, (int) env('ATLAS_LOOP_SLEEP_SECONDS', 0)),
            // Transient-DB resilience: a brief Postgres blip during a 24h run is a
            // recoverable hiccup, not a fatal crash. Each durable hot-path write is
            // retried with reconnect + exponential backoff; a per-cycle DB failure that
            // survives the retries parks the cycle (log + skip + continue) and the
            // campaign is aborted only after the DB is unreachable for the sustained
            // window. Propose-only — grind/judge behaviour is untouched.
            'db_retry_attempts' => max(1, (int) env('ATLAS_LOOP_DB_RETRY_ATTEMPTS', 5)),
            'db_retry_base_ms' => max(10, (int) env('ATLAS_LOOP_DB_RETRY_BASE_MS', 500)),
            'db_retry_max_ms' => max(100, (int) env('ATLAS_LOOP_DB_RETRY_MAX_MS', 30000)),
            'db_outage_abort_seconds' => max(30, (int) env('ATLAS_LOOP_DB_OUTAGE_ABORT_SECONDS', 180)),
            'db_outage_poll_seconds' => max(1, (int) env('ATLAS_LOOP_DB_OUTAGE_POLL_SECONDS', 15)),
        ],

        // The parallel worker pool ships BUILT but GATED OFF: serial single-worker is the
        // proven default. Enabling N workers is a measured flip (needs a real-Postgres
        // no-double-claim run + per-worker /tmp namespacing + disk cap divided by workers),
        // not assumed free — treat --workers>1 as experimental until that flip is tested.
        'parallel' => [
            'enabled' => (bool) env('ATLAS_LOOP_PARALLEL_ENABLED', false),
            'max_workers' => max(1, (int) env('ATLAS_LOOP_MAX_WORKERS', 4)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Software Company Stewardship Stack
    |--------------------------------------------------------------------------
    |
    | AP-764 keeps the 24h runner native to Atlas Server. External tools may
    | invoke the command, but the product boundary is this server command plus
    | AP-745/AP-746 locks, budgets, rate limit, pause and kill switch.
    */
    /*
    |--------------------------------------------------------------------------
    | Foundry — Governed RSI (Recursive Self-Improvement) substrate
    |--------------------------------------------------------------------------
    | Part A (Build-Safety) flag. ATLAS_RSI_MODE governs whether the loop may
    | improve its OWN machinery. DEFAULT-OFF: with the flag off the RSI proposal
    | gate is inert and the existing loop is byte-identical. The RSI path is
    | ALWAYS proposal-only + human-gated and is screened, before any human gate,
    | by the fail-closed RsiInvariantGuardService against the frozen
    | ImmutableInvariantRegistryService sacred set. The flag NEVER grants
    | apply/canonize authority — it only un-mutes the proposal-only seam.
    */
    'foundry' => [
        'rsi' => [
            'mode' => (bool) env('ATLAS_RSI_MODE', false),

            // Earned-Autonomy layer master switch. DEFAULT-OFF. This flag does NOT
            // by itself authorize anything: it only un-mutes the EarnedAutonomyGate
            // consultation. KillAuthority::isArmed() must ALSO be true for any
            // auto_apply. With the flag off, EarnedAutonomyGateService::decide()
            // ALWAYS returns decision=human_gate (byte-identical to today's
            // proposal-only behaviour).
            'earned_autonomy' => [
                'mode' => (bool) env('ATLAS_EARNED_AUTONOMY_MODE', false),
            ],
        ],
    ],

    'software_company_stewardship' => [
        // Foundry AP-C Frontier mode. DEFAULT-OFF single source of truth, read by
        // BOTH the AP-B exhaustion/rarity gate and the AP-C generation orchestrator.
        // When false, generation is refused (honest skip / frontier_mode_off). It can
        // never be flipped by a transient CLI/input arg — only by this persistent config.
        'frontier_mode' => (bool) env('ATLAS_FOUNDRY_FRONTIER_MODE', false),

        // POINT 1 — broaden the loop's finding source: when true, the deep-scan mines the
        // canonical docs' frontmatter (next_actions/allowed_changes) so the operator-written
        // AAEOS/factory backlog becomes admissible findings. DEFAULT-OFF (byte-identical when
        // off). Persistent config, never a transient arg.
        'scan_canonical_doc_backlog' => (bool) env('ATLAS_STEWARDSHIP_SCAN_CANONICAL_DOC_BACKLOG', false),

        // POINT 3 — operator authorization for AUTONOMOUS execution of the doc backlog:
        // when true, doc-mined findings that resolved real code file scope become
        // auto-executable (and survive the factory gate's origin veto); all other quality
        // gates still run. DEFAULT-OFF — doc directives stay operator-review-gated otherwise.
        'autonomous_doc_backlog_execution' => (bool) env('ATLAS_STEWARDSHIP_AUTONOMOUS_DOC_BACKLOG_EXECUTION', false),

        // FASE 4 / Pilar 2 — evidence-anchored SEMANTIC capability gap-finder as a
        // finding source: compares documented capability claims vs runtime reality
        // and emits concrete drift gaps (each with an outcome_contract) instead of
        // boilerplate "keep doc in sync" pseudo-gaps. DEFAULT-OFF (byte-identical
        // when off). Persistent config, never a transient arg.
        'scan_semantic_capability_gaps' => (bool) env('ATLAS_STEWARDSHIP_SCAN_SEMANTIC_CAPABILITY_GAPS', false),

        // EXTREME language-quality gate. Diff-scoped LOCAL tools verify each
        // cycle's changed files before merge. Default 'off' so the existing test
        // suite (which resolves the REAL service) is byte-identical. The unattended
        // production loop runs with ATLAS_STEWARDSHIP_LANGUAGE_QUALITY=enforce.
        // A touched language absent from 'toolchains' is FAIL-CLOSED (blocks).
        'language_quality' => [
            'enforcement' => env('ATLAS_STEWARDSHIP_LANGUAGE_QUALITY', 'off'),
            'toolchains' => [
                'php' => ['tools' => ['phpstan']],
                // python/go/swift/ts/js intentionally ABSENT => fail-closed BLOCK
                // until each local OSS toolchain is wired and declared here.
            ],
        ],

        'native_obra_runner' => [
            'enabled' => (bool) env('ATLAS_STEWARDSHIP_NATIVE_OBRA_RUNNER_ENABLED', false),
            'area_id' => env('ATLAS_STEWARDSHIP_NATIVE_OBRA_RUNNER_AREA', 'agentic_engineering_os'),
            'record_runs' => (bool) env('ATLAS_STEWARDSHIP_NATIVE_OBRA_RUNNER_RECORD_RUNS', true),
            'min_interval_seconds' => (int) env('ATLAS_STEWARDSHIP_NATIVE_OBRA_RUNNER_MIN_INTERVAL_SECONDS', 900),
        ],

        // AP-745 scheduler-safe Continuous Stewardship Loop tick. Disabled by
        // default; these keys only formalize the defaults the service already
        // assumes so an operator can tune them without touching code.
        'continuous_loop' => [
            'enabled' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_LOOP_ENABLED', false),
            'kill_switch' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_LOOP_KILL_SWITCH', false),
            'min_interval_seconds' => (int) env('ATLAS_STEWARDSHIP_CONTINUOUS_LOOP_MIN_INTERVAL_SECONDS', 900),
            'lock_ttl_seconds' => (int) env('ATLAS_STEWARDSHIP_CONTINUOUS_LOOP_LOCK_TTL_SECONDS', 600),
        ],

        // AP-746 recurring scheduler-safe boundary around AP-745. Disabled by
        // default; never installs a scheduler.
        'continuous_scheduler' => [
            'enabled' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_SCHEDULER_ENABLED', false),
            'kill_switch' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_SCHEDULER_KILL_SWITCH', false),
        ],

        // AP-766 Continuous Stewardship Runner control plane. Composes AP-746
        // and owns the runner-level primitives: per-area kill switch, daily run
        // budget per area, runner lock TTL and rate limit. Disabled by default;
        // scheduler-safe (callable by an external scheduler) but installs none.
        'continuous_runner' => [
            'enabled' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_ENABLED', false),
            'area_id' => env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_AREA', 'agentic_engineering_os'),
            'kill_switch' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_KILL_SWITCH', false),
            'area_kill_switch' => (bool) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_AREA_KILL_SWITCH', false),
            'min_interval_seconds' => (int) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_MIN_INTERVAL_SECONDS', 900),
            'lock_ttl_seconds' => (int) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_LOCK_TTL_SECONDS', 600),
            'max_runs_per_day' => (int) env('ATLAS_STEWARDSHIP_CONTINUOUS_RUNNER_MAX_RUNS_PER_DAY', 48),
        ],

        // AP-801 multi-agent workcell. Disabled by default: an AP-786 cycle runs
        // as a single owner-flow pass unless this flag (or the --multi-agent-workcell
        // CLI option) is explicitly set, in which case each executed cycle is also
        // projected through the AP-795..AP-800 lane workcell
        // (context_scout -> architect -> implementer -> reviewer -> repair -> judge)
        // composed by MultiAgentLiveCycleExecutorService. The workcell never invokes
        // a provider itself; it composes the cycle's real owner-runtime result.
        'multi_agent_workcell' => (bool) env('ATLAS_STEWARDSHIP_MULTI_AGENT_WORKCELL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | AAEOS HTTP Path Integration (T1.4 / AP-696)
    |--------------------------------------------------------------------------
    |
    | Controls the four-phase migration from the legacy productive HTTP path
    | (AiInteractionController::store -> AiWorker -> AtlasProgrammingOrchestrator)
    | to the canonical AAEOS Kernel sequence (intent -> placement -> classify
    | -> policy -> topology -> routing -> spec -> tasks -> receipt -> exec
    | -> gates -> evidence -> delivery -> human review -> cert -> learning).
    |
    | `http_path_phase` accepted values:
    |   - 'legacy' (default): no facade; current pipeline runs unchanged.
    |   - '1'  : Phase 1 — Place Feature mandatory; Mission Foundation optional.
    |   - '2'  : Phase 2 — AI Router + Policy gate mandatory (future).
    |   - '3'  : Phase 3 — AAWR + Decide mandatory for R3+ (future).
    |   - '4'  : Phase 4 — Company Runtime + AiWorker thin delegator (future).
    |
    | Canonical spec: docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md
    | Implementation AP: docs/ap/AP-696-aaeos-http-path-facade-phase-1-contract.md
    */
    'aaeos' => [
        'http_path_phase' => env('ATLAS_AAEOS_HTTP_PATH_PHASE', 'legacy'),
        'placement_cache_ttl_seconds' => (int) env('ATLAS_AAEOS_PLACEMENT_CACHE_TTL_SECONDS', 300),
        'telemetry_enabled' => (bool) env('ATLAS_AAEOS_TELEMETRY_ENABLED', true),
        'mission_foundation_optional_at_phase_1' => (bool) env('ATLAS_AAEOS_MISSION_OPTIONAL_PHASE_1', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Code Graph (AP-811 P0)
    |--------------------------------------------------------------------------
    |
    | Controls the migration of the world-model edges
    | (App\Models\AiCodebaseWorldModelEdge) from fixture-seeded values
    | (AtlasAutonomousEngineeringService::buildWorldModel) to the real,
    | confidence-graded edges produced by the keystone resolver
    | (App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver). The traversal
    | ranker (App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker)
    | reads whatever edges are persisted.
    |
    |   - `real_edges` (default OFF): when true, persist resolver-derived edges
    |     instead of fixtures. Stays off until the operator reviews the real
    |     graph; fixtures remain the safe default.
    |   - `max_edges`: hard upper bound on edges persisted per world model.
    |   - `traversal_max_depth` / `traversal_max_nodes`: traversal safety caps so
    |     graph walks stay bounded on large indexes.
    |
    | Keystone: app/Services/Engineering/CodeGraph/CodeGraphEdgeResolver.php
    */
    'code_graph' => [
        'real_edges' => (bool) env('ATLAS_CODE_GRAPH_REAL_EDGES', false),
        'max_edges' => (int) env('ATLAS_CODE_GRAPH_MAX_EDGES', 200000),
        'traversal_max_depth' => (int) env('ATLAS_CODE_GRAPH_TRAVERSAL_MAX_DEPTH', 4),
        'traversal_max_nodes' => (int) env('ATLAS_CODE_GRAPH_TRAVERSAL_MAX_NODES', 60),
        // AP-815 W-1: stable id of the PRIMARY workspace (the running app). Any other
        // indexed project is keyed by its own resolved workspace_id (git-remote slug or
        // basename+hash) so cross-project graphs never collide. See CodeGraphWorkspaceIdentity.
        'default_workspace_id' => (string) env('ATLAS_CODE_GRAPH_DEFAULT_WORKSPACE_ID', 'atlas-server'),
        // AP-815 Q-4: anti-over-claim bounds on INFERRED edges (EXTRACTED always trusted).
        'min_inferred_score' => (float) env('ATLAS_CODE_GRAPH_MIN_INFERRED_SCORE', 0.2),
        'max_inferred_ratio' => (float) env('ATLAS_CODE_GRAPH_MAX_INFERRED_RATIO', 0.35),
        // AP-815 Q-3: fraction a node/edge count may fall between two index runs before
        // CodeGraphRegressionDetector flags it a regression. Clamped to (0,1] at read.
        'regression_drop_ratio' => (float) env('ATLAS_CODE_GRAPH_REGRESSION_DROP_RATIO', 0.25),
        // AP-815 G-7: sovereignty — who may read a workspace graph by privacy class.
        'trusted_actors' => ['atlas-kernel', 'local', 'operator'],
        'sovereign_actors' => ['atlas-kernel', 'local'],
        // AP-815 G-1: workspace -> privacy class map + default (feeds G-7 enforcement).
        'workspace_privacy' => [],
        'default_privacy_class' => (string) env('ATLAS_CODE_GRAPH_DEFAULT_PRIVACY_CLASS', 'internal'),
        // AP-815 E-8: max graph hops a context candidate may sit from a query seed.
        'max_context_distance' => (int) env('ATLAS_CODE_GRAPH_MAX_CONTEXT_DISTANCE', 2),
        // AP-815 Q-1 / D-1: graph-health float precision + adjacency index node ceiling.
        'health_precision' => (int) env('ATLAS_CODE_GRAPH_HEALTH_PRECISION', 6),
        'max_adjacency_nodes' => (int) env('ATLAS_CODE_GRAPH_MAX_ADJACENCY_NODES', 200000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Compression Layer (AP-813)
    |--------------------------------------------------------------------------
    |
    | Captures the headroom compression axis as a GOVERNED layer ON TOP of the
    | code-graph: it shrinks the bytes that reach a provider (tool-output JSON,
    | logs, search results, diffs, text) and stabilizes the prompt prefix for
    | KV-cache reuse, with reversible retrieval (CCR) backed by the durable
    | Evidence Ledger — lossless-by-governance, NOT a TTL cache.
    |
    | Quality contract: compression is information-preserving by construction
    | (errors/outliers/unique content are kept unconditionally; only provably
    | redundant homogeneous bulk is sampled) and FAIL-OPEN (any error passes the
    | original through, never blocking a provider call). Default OFF; the operator
    | flips it on after review. Pipeline: app/Services/Ai/Compression/CompressionPipeline.php
    |
    |   - `enabled` (default OFF): master switch for the whole layer.
    |   - `min_block_chars`: a content block is only considered for compression
    |     above this size (small blocks: overhead > saving → passed through).
    |   - `cache_aligner.enabled`: relocate volatile tokens (dates/UUIDs/trace ids)
    |     out of the stable prefix to a labelled tail block (info-preserving).
    |   - `ccr.*`: reversible Compress-Cache-Retrieve over the Evidence Ledger.
    |   - `smart_crusher.*`: anchors kept from head/tail + importance cap.
    |   - `compressors`: per-content-type enable map.
    */
    'compression_layer' => [
        'enabled' => (bool) env('ATLAS_COMPRESSION_LAYER_ENABLED', false),
        'min_block_chars' => (int) env('ATLAS_COMPRESSION_MIN_BLOCK_CHARS', 800),
        'cache_aligner' => [
            'enabled' => (bool) env('ATLAS_COMPRESSION_CACHE_ALIGNER', true),
        ],
        'ccr' => [
            'enabled' => (bool) env('ATLAS_COMPRESSION_CCR', true),
            'codec' => (string) env('ATLAS_COMPRESSION_CCR_CODEC', 'gzip'),
        ],
        'smart_crusher' => [
            'keep_head' => (int) env('ATLAS_COMPRESSION_KEEP_HEAD', 8),
            'keep_tail' => (int) env('ATLAS_COMPRESSION_KEEP_TAIL', 4),
            'max_keep' => (int) env('ATLAS_COMPRESSION_MAX_KEEP', 40),
        ],
        'compressors' => [
            'json' => (bool) env('ATLAS_COMPRESSION_JSON', true),
            'log' => (bool) env('ATLAS_COMPRESSION_LOG', true),
            'search' => (bool) env('ATLAS_COMPRESSION_SEARCH', true),
            'diff' => (bool) env('ATLAS_COMPRESSION_DIFF', true),
            'text' => (bool) env('ATLAS_COMPRESSION_TEXT', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Cross-Domain Graph (AP-814 · M-8, Fase-1)
    |--------------------------------------------------------------------------
    |
    | One governed ENTITY graph over the Atlas domains (code is one slice). Fase-1
    | is read-only: it assembles the real cross-domain graph from `ai_domain_profiles`
    | (domain nodes) + `ai_domain_handoffs` (real domain→domain edges) in the canonical
    | node/edge shape so the existing domain-agnostic analytics/traversal run unchanged.
    | Cross-domain crossing is governed by the EXISTING AtlasCrossDomainMeshService /
    | ARPTL (Fase-2). Default OFF; nothing is persisted or crossed in Fase-1.
    |
    | Service: app/Services/Engineering/CodeGraph/CrossDomainGraphIngestionService.php
    */
    'cross_domain_graph' => [
        'enabled' => (bool) env('ATLAS_CROSS_DOMAIN_GRAPH_ENABLED', false),
        'max_domains' => (int) env('ATLAS_CROSS_DOMAIN_GRAPH_MAX_DOMAINS', 100),
        'max_edges' => (int) env('ATLAS_CROSS_DOMAIN_GRAPH_MAX_EDGES', 5000),
        // Fase-3: include per-domain real entities (runtime records / evidence packs / claims).
        'entities' => (bool) env('ATLAS_CROSS_DOMAIN_GRAPH_ENTITIES', true),
        'max_entities' => (int) env('ATLAS_CROSS_DOMAIN_GRAPH_MAX_ENTITIES', 5000),
        // Fase-2: ARPTL-gated traversal safety caps.
        'traversal_max_depth' => (int) env('ATLAS_CROSS_DOMAIN_GRAPH_TRAVERSAL_MAX_DEPTH', 4),
        'traversal_max_nodes' => (int) env('ATLAS_CROSS_DOMAIN_GRAPH_TRAVERSAL_MAX_NODES', 60),
        // Fase-3: persist the assembled graph into a `cross-domain` world model (gated).
        'persist' => (bool) env('ATLAS_CROSS_DOMAIN_GRAPH_PERSIST', false),
    ],
];
