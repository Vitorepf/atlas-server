<?php

return [
    'version' => env('ATLAS_VERSION'),
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
        'embedding_provider' => env('ATLAS_SEMANTIC_EMBEDDING_PROVIDER', env('OPENAI_API_KEY') ? 'openai' : 'local_hash'),
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
                'default_sensitivity' => env('ATLAS_PRIVACY_BLACKINK_DEFAULT', 'normal'),
                'external_ai_policy' => 'allow',
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

    'display' => [
        'busy_input_mode' => env('ATLAS_BUSY_INPUT_MODE', 'interrupt'),
    ],

    'mobile' => [
        'enabled' => (bool) env('ATLAS_MOBILE_ENABLED', false),
        'pairing_ttl_minutes' => (int) env('ATLAS_MOBILE_PAIRING_TTL_MINUTES', 60),
        'max_devices' => (int) env('ATLAS_MOBILE_MAX_DEVICES', 5),
        'push_provider' => env('ATLAS_MOBILE_PUSH_PROVIDER', 'expo'),
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
        'self_diagnostic' => [
            'enabled' => (bool) env('ATLAS_INIT_SELF_DIAGNOSTIC', false),
            'time' => env('ATLAS_INIT_SELF_DIAGNOSTIC_TIME', '06:15'),
            'recent_days' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_RECENT_DAYS', 7),
            'baseline_days' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_BASELINE_DAYS', 30),
            'min_recent_samples' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_MIN_RECENT_SAMPLES', 4),
            'min_baseline_samples' => (int) env('ATLAS_INIT_SELF_DIAGNOSTIC_MIN_BASELINE_SAMPLES', 6),
            'score_drop_threshold' => (float) env('ATLAS_INIT_SELF_DIAGNOSTIC_SCORE_DROP_THRESHOLD', 12),
            'failure_rate_increase_threshold' => (float) env('ATLAS_INIT_SELF_DIAGNOSTIC_FAILURE_RATE_INCREASE_THRESHOLD', 0.2),
        ],
        'proposal_scan' => [
            'enabled' => (bool) env('ATLAS_INIT_PROPOSAL_SCAN', false),
            'time' => env('ATLAS_INIT_PROPOSAL_SCAN_TIME', '06:30'),
            'workspace' => env('ATLAS_INIT_PROPOSAL_SCAN_WORKSPACE', dirname(base_path())),
            'limit' => (int) env('ATLAS_INIT_PROPOSAL_SCAN_LIMIT', 3),
            'large_service_lines' => (int) env('ATLAS_INIT_PROPOSAL_LARGE_SERVICE_LINES', 420),
        ],
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
            'default_mode' => env('ATLAS_AI_TOOL_PERMISSION_MODE', 'read'),
            'allow_danger' => (bool) env('ATLAS_AI_TOOL_ALLOW_DANGER', false),
            'allow_unsandboxed_write' => (bool) env('ATLAS_AI_ALLOW_UNSANDBOXED_WRITE', false),
            'allowed_roots' => env('ATLAS_AI_TOOL_ALLOWED_ROOTS')
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_TOOL_ALLOWED_ROOTS'))), fn (string $root): bool => $root !== ''))
                : [dirname(base_path()), base_path()],
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
                'args' => env('ATLAS_AI_CLAUDE_ARGS')
                    ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CLAUDE_ARGS'))), fn (string $arg): bool => $arg !== ''))
                    : ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
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

    'cli' => [
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
];
