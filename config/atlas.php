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

    /*
    |--------------------------------------------------------------------------
    | Memory Conflict — kernel consolidation
    |--------------------------------------------------------------------------
    |
    | Three AAEOS pure kernels are consolidated into the memory-conflict path
    | (AtlasMemoryConflictResolutionService). Their CONSTANTS were a true mirror
    | of the consumer and are now deduped (the kernels reference the consumer's
    | public constants — single source of truth, behavior-preserving). Their
    | CLASSIFY/RESOLVE methods are NEW behavior the consumer never had, so they
    | are wired here behind default-OFF flags: consolidated + ready, but the live
    | path is unchanged until the operator opts in. When OFF, the new helper
    | methods on the consumer are inert (return null).
    |
    | - MemoryConflictVerbClassifier: derives the relation verb from
    |   key/scope/polarity/ts (the consumer otherwise receives the verdict).
    | - MemoryConflictAxisResolver: authority>evidence>freshness tie-break.
    | - MemoryScopeContradictionClassifier: scope-rank contradiction taxonomy.
    | - FactPairPolarityContradictionDetector: polarity/value contradiction for a
    |   pair of atomic facts (Cognition kernel; new detector the consumer lacks).
    | - NumericRangeOverlapContradictionDetector: inclusive numeric-range overlap
    |   relationship (Cognition kernel; new detector the consumer lacks).
    | - TemporalSupersessionClassifier: which timestamped assertion supersedes the
    |   other on the same logical key (Cognition kernel; new detector the consumer
    |   lacks).
    */
    'memory_conflict' => [
        'verb_classifier_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_VERB_CLASSIFIER_ENABLED', false),
        'axis_resolver_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_AXIS_RESOLVER_ENABLED', false),
        'scope_contradiction_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_SCOPE_CONTRADICTION_ENABLED', false),
        'fact_polarity_contradiction_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_FACT_POLARITY_CONTRADICTION_ENABLED', false),
        'numeric_range_overlap_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_NUMERIC_RANGE_OVERLAP_ENABLED', false),
        'temporal_supersession_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_TEMPORAL_SUPERSESSION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim-coherence cognitive kernels
    |--------------------------------------------------------------------------
    | Pure deterministic kernels under App\Services\Ai\Cognitive\ClaimCoherence\,
    | consolidated into the live context-path gate ContextPackSelfReflectionGate.
    | Every flag is DEFAULT-OFF: each kernel adds NEW behavior the gate's existing
    | substring contradiction scan does not have, so blind activation would change
    | live context-gate verdicts. With all flags OFF assess() output is byte-identical
    | to the pre-wiring behavior. The operator activates a kernel later via env/flag.
    |   - HedgeCertaintyConflictDetector: real hedge-vs-absolute contradiction signal
    |     wired into assess()->hasContradiction() (live call-chain when ON).
    |   - ClaimSelfCoherenceScorer: per-claim self-coherence score, wired into
    |     assess()->hasLowClaimCoherence() (RISKY when incoherent; live call-chain when
    |     ON) AND exposed as the scoreClaimSelfCoherence() direct helper.
    |   - ClaimQualifierStrengthClassifier: qualifier-strength band, wired into
    |     assess()->hasLowClaimCoherence() (RISKY when a hard modal band carries no
    |     reusable evidence; live call-chain when ON) AND exposed as the
    |     classifyQualifierStrength() direct helper.
    */
    'claim_coherence' => [
        'hedge_certainty_conflict_enabled' => (bool) env('ATLAS_CLAIM_COHERENCE_HEDGE_CERTAINTY_CONFLICT_ENABLED', false),
        'self_coherence_enabled' => (bool) env('ATLAS_CLAIM_COHERENCE_SELF_COHERENCE_ENABLED', false),
        'qualifier_strength_enabled' => (bool) env('ATLAS_CLAIM_COHERENCE_QUALIFIER_STRENGTH_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cognitive-immune promotion gate kernel
    |--------------------------------------------------------------------------
    | Pure deterministic kernel App\Services\Ai\Cognition\
    | CognitiveImmunePromotionGateEvaluator, consolidated into the live
    | AtlasMemoryCognitiveImmuneLearningKernelService as an opt-in helper.
    | DEFAULT-OFF: the evaluator ADDS richer G0-G8 semantics the consumer's own
    | evaluatePromotion() does not have (forbid-gate default-pass for G3/G4,
    | confirm-gates that stay pending until evidence, and a distinct
    | blocked/trusted/watch/candidate/unclassified promotion_status taxonomy).
    | With the flag OFF the consumer's evaluatePromotion() output is unchanged;
    | the operator activates the kernel later via env/flag.
    */
    'cognitive_immune' => [
        'promotion_gate_evaluator_enabled' => (bool) env('ATLAS_COGNITIVE_IMMUNE_PROMOTION_GATE_EVALUATOR_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context-window / retrieval budget kernels
    |--------------------------------------------------------------------------
    | Three pure deterministic kernels under App\Services\Ai\Context\,
    | consolidated into the live context-window / token-economy / retrieval
    | path. Every flag is DEFAULT-OFF: each kernel ADDS NEW behavior the live
    | consumer does not already have, so blind activation would change live
    | output. With all flags OFF the consumers' output is byte-identical to the
    | pre-wiring behavior (the kernels are never invoked). The operator
    | activates a kernel later via env/flag.
    |
    | - ContextWindowMustKeepBudgetAllocator (consumer:
    |   AtlasTokenEconomyRuntimeService::optimize). The compiler's inline
    |   allocate() force-includes every must_keep segment even past the budget
    |   (budget_exceeded_by_must_keep) with NO degradation plan. This kernel adds
    |   an honest overflow degradation plan: greedy rank-fit, per-segment
    |   compression targets that recover the deficit, coverage<1.0 detection and
    |   unrecoverable-overflow blockers. When ON it appends a
    |   `must_keep_budget_allocation` analysis section (advisory; does not mutate
    |   the existing compression/quality receipts).
    | - RecallContextBudgetSplitScorer (consumer:
    |   AtlasTokenEconomyRuntimeService::optimize). No live recall-vs-context
    |   char split exists today. When ON it appends a `recall_context_split`
    |   receipt (signal-driven ratio with floor/ceiling/risk-cap). Advisory only.
    | - RetrievalFanoutGate (consumer: ContextRetrievalRouter::plan). The router
    |   enables sources via boolean heuristics (needsCode/needsEvidence/...), not
    |   numeric per-dimension score thresholds. When ON, and only when the caller
    |   supplies numeric `fanout_scores` in $options, it appends a `fanout_gate`
    |   section (memory/code/docs run/skip decision). It NEVER changes
    |   selected_sources or readiness.
    */
    'context_budget' => [
        'must_keep_allocator_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_MUST_KEEP_ALLOCATOR_ENABLED', false),
        'recall_split_scorer_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_RECALL_SPLIT_SCORER_ENABLED', false),
        'retrieval_fanout_gate_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_RETRIEVAL_FANOUT_GATE_ENABLED', false),
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
        // R1: embed atlas_memory_entries + atlas_verbatim_memories on write and
        // rank recall by real pgvector similarity (pgsql-only; falls back to the
        // lexical path on sqlite / when the embedding engine is unavailable).
        'memory_vector_recall_enabled' => (bool) env('ATLAS_SEMANTIC_MEMORY_VECTOR_RECALL_ENABLED', true),
        'max_embedding_chars' => (int) env('ATLAS_SEMANTIC_MAX_EMBEDDING_CHARS', 12000),
        'activation_daily_limit' => (int) env('ATLAS_SEMANTIC_ACTIVATION_DAILY_LIMIT', 2),
        'activation_pending_limit' => (int) env('ATLAS_SEMANTIC_ACTIVATION_PENDING_LIMIT', 4),
        'activation_note_cooldown_days' => (int) env('ATLAS_SEMANTIC_ACTIVATION_NOTE_COOLDOWN_DAYS', 7),
        'curation_min_content_chars' => (int) env('ATLAS_SEMANTIC_CURATION_MIN_CONTENT_CHARS', 160),
        'curation_min_density_score' => (float) env('ATLAS_SEMANTIC_CURATION_MIN_DENSITY_SCORE', 0.42),
        'enqueue_ai_clarification' => (bool) env('ATLAS_SEMANTIC_ENQUEUE_AI_CLARIFICATION', false),
        // R8: path to the HONEST labeled retrieval-precision corpus (independent
        // queries → relevant doc ids). Defaults to the shipped fixture under
        // resources/atlas/local_rag. Override only to point at a larger curated
        // corpus; the harness fails honest (status=attention, unmeasured) if it
        // is missing or the real semantic engine is unavailable — never fabricates.
        'independent_precision_corpus_path' => env('ATLAS_LOCAL_RAG_PRECISION_CORPUS_PATH'),
    ],

    'aucri' => [
        // AUCRI `semantic_candidate` scoring: when the LOCAL Python semantic_rag
        // runtime is available, AHRI scores the ASEF manifest chunks with REAL
        // cosine similarity (one batched retrieve() per report) and the ranking
        // channel becomes `local_semantic_vector`. When the runtime is
        // unavailable, errors, or this flag is off, the static manifest
        // placeholder (score_hint 0.60, channel `manifest_pending_embedding`)
        // passes through UNTOUCHED — honest degrade, never a fabricated score.
        'local_semantic_scoring' => (bool) env('ATLAS_AUCRI_LOCAL_SEMANTIC_SCORING', true),
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
            // R4 (PART A): surface the operator's accrued, SEMANTIC memory recall content
            // (decisions/learnings) into the live prompt via the now-pgvector
            // AtlasHybridMemoryRetrievalService::recall. Default-OFF: when false the
            // injection never resolves the retrieval service, touches the DB, or alters the
            // deterministic hash, so the prompt stays byte-identical to the pre-wiring path
            // (same contract as the AP-815 code_graph.auto_context block above).
            'include_memory_recall' => (bool) env('ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_MEMORY_RECALL', false),
            'memory_recall_limit' => (int) env('ATLAS_OPEN_BRAIN_INJECTION_MEMORY_RECALL_LIMIT', 6),
            // F3 (Salto 1 — AURG vivo): surface the fused Unified Reality Graph's TOP
            // cross-layer chains (memory/code/domains/evidence/strategic, built by
            // atlas:aurg:ingest) into the live prompt via AtlasRealityGraphQueryService —
            // the SAME engine behind atlas:aurg:query, not a second graph. PROVIDER-BOUND
            // ALWAYS on this path (hard-coded): seeds and every BFS step are restricted to
            // provider_safe && !sensitive nodes inside the query service, so sensitive
            // domains and anything reachable only through them never ride a prompt.
            // Default-OFF: when false the injection never resolves the query service,
            // touches the DB, or alters the deterministic hash, so the prompt stays
            // byte-identical to the pre-wiring path (same contract as the
            // include_memory_recall block above).
            'include_reality_graph' => (bool) env('ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_REALITY_GRAPH', false),
            'reality_graph_limit' => (int) env('ATLAS_OPEN_BRAIN_INJECTION_REALITY_GRAPH_LIMIT', 6),
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

        // L3-10: custo medido por execução (eixo custo do N×M, antes 100% cego). Overrides
        // opcionais sobre os defaults in-class do ProviderCostEstimator (tokens×rate, ou
        // runtime×taxa/min p/ providers locais sem tokens). Vazio = usa os defaults seguros.
        'cost' => [
            'rates' => [], // ['<provider>' => ['in' => <usd_por_1k>, 'out' => <usd_por_1k>]]
            'runtime_usd_per_minute' => (float) env('ATLAS_AI_COST_RUNTIME_USD_PER_MINUTE', 0.02),
        ],

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
            // Canonical append-only ledger path. Empty => storage/atlas/governance/change_class_trust.jsonl.
            // The L6-14 real-history feed (AtlasLoopAutoMergeService) and the operator CLI both write here.
            'log_path' => (string) env('ATLAS_TRUST_LADDER_LOG_PATH', ''),
            'thresholds' => [
                // Clean-streak count the operator must set to unlock each tier. Non-positive
                // => disabled. Still capped by risk and by eligible/blocked class policy.
                'draft' => (int) env('ATLAS_TRUST_LADDER_DRAFT_THRESHOLD', 0),
                'execute_with_approval' => (int) env('ATLAS_TRUST_LADDER_EXECUTE_WITH_APPROVAL_THRESHOLD', 0),
                'autonomous' => (int) env('ATLAS_TRUST_LADDER_AUTONOMOUS_THRESHOLD', 0),
            ],
            'eligible_classes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('ATLAS_TRUST_LADDER_ELIGIBLE_CLASSES', '')),
            ))),
            'blocked_class_patterns' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('ATLAS_TRUST_LADDER_BLOCKED_CLASS_PATTERNS', 'never_merge,merge_gate,constitutional_kernel,harness_guard,frozen_judge,formal_invariant,kernel,security_sensitive,provider_secret')),
            ))),
            // L6-14: capstone proof that a class-scoped release is earned by
            // evidence and revoked by regression, without touching never-merge.
            'release_gate' => [
                'enabled' => (bool) env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_ENABLED', true),
                'schedule_enabled' => (bool) env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_SCHEDULE_ENABLED', true),
                'schedule_time' => env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_SCHEDULE_TIME', '07:40'),
                'target_class' => env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_TARGET_CLASS', 'documentation_only'),
                'min_clean_streak' => (int) env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_MIN_CLEAN_STREAK', 3),
                'blocked_class_probe' => env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_BLOCKED_CLASS_PROBE', 'constitutional_kernel'),
                'receipt_path' => env('ATLAS_CHANGE_CLASS_TRUST_RELEASE_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/change-class-trust-release-gate.json')),
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
        //   mode = off | observe | enforce (DEFAULT, desde O-2 da campanha Fable)
        //     observe — annotate + log what it WOULD prune; persists everything (no change)
        //     enforce — noise is NOT persisted; identical content collapses
        // DEFAULT enforce: o Marco Zero mediu 94% de waste em observe; os fixes do sweep
        // O-1 (falso-positivo smoke-test, canonicalização do hash, dedup que respeita
        // rejeição humana) tornaram enforce seguro — não dropa learning real.
        // Audit any time (read-only): php artisan atlas:ai:capture-quality-audit
        'capture_quality_gate' => [
            'mode' => env('ATLAS_CAPTURE_QUALITY_MODE', 'enforce'),
            'min_score' => (int) env('ATLAS_CAPTURE_QUALITY_MIN_SCORE', 20),
        ],

        // AP-819 Obra A (F1) — auto-feed do cérebro de falhas. Harvester lê falhas
        // REAIS de runtime (ai_job_attempts failed/timeout + ledger OPERATION_FAILED)
        // e alimenta failure_signatures via o classificador existente, preservando o
        // pipeline recurrence→alert. Observe-only: escreve SÓ no corpus de falhas;
        // nenhuma proposta/mutação downstream. Idempotente por envelope_id.
        'failure_auto_feed' => [
            'enabled' => (bool) env('ATLAS_FAILURE_AUTO_FEED_ENABLED', false),
            'window_hours' => (int) env('ATLAS_FAILURE_AUTO_FEED_WINDOW_HOURS', 24),
            'max_per_run' => (int) env('ATLAS_FAILURE_AUTO_FEED_MAX_PER_RUN', 200),
            'domain' => env('ATLAS_FAILURE_AUTO_FEED_DOMAIN', 'engineering'),
        ],

        // L5-3 — Auto-cura da suíte real. O snapshot SEMANAL do número REAL de testes
        // vermelhos (derivado de um relatório de teste real) é a fonte-de-verdade do
        // trend que destrava o claim L5-3. Gravado por ISO-week, idempotente por semana.
        // O scheduler consome o relatório de teste real mais recente em `report_path`
        // (escrito pela suíte/CI). Default OFF; nunca corrige nem quarentena testes —
        // só MEDE e persiste. Lido por AtlasFailureWeeklyRedSnapshotCommand.
        'suite_red_snapshot' => [
            'schedule_enabled' => (bool) env('ATLAS_SUITE_RED_SNAPSHOT_SCHEDULE_ENABLED', false),
            'schedule_day' => env('ATLAS_SUITE_RED_SNAPSHOT_SCHEDULE_DAY', 'monday'),
            'schedule_time' => env('ATLAS_SUITE_RED_SNAPSHOT_SCHEDULE_TIME', '06:30'),
            'domain' => env('ATLAS_SUITE_RED_SNAPSHOT_DOMAIN', 'programming'),
            'report_path' => env('ATLAS_SUITE_RED_SNAPSHOT_REPORT_PATH', storage_path('app/atlas/evidence/suite-red-latest.json')),
        ],

        // G4 — governed promotion of a STAGED self-construction scaffold to a NEW
        // git branch (worktree-isolated; never main, never the operator's working
        // tree). Default OFF; explicit per-call operator approval is ALWAYS
        // required even when enabled. Read by AtlasSelfConstructionPromotionExecutorService.
        'self_construction' => [
            'promote_to_source_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_PROMOTE_ENABLED', false),
            'tool_gap_schedule_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_SCHEDULE_ENABLED', true),
            'tool_gap_schedule_time' => (string) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_SCHEDULE_TIME', '05:50'),

            // L5-4 keystone · the recurrent-capability-gap bridge. Reads the Loop
            // loss-observer's dominant loss patterns, classifies the subset that
            // signal a MISSING TOOL/CAPABILITY (fixture builder, contract linter,
            // worktree/framework-gate helper, …) rather than an ordinary code bug,
            // and routes them into the governed self-construction corridor as a
            // parked proposal (requires_human_approval=true). NEVER approves,
            // stages, promotes, merges, or runs a provider. Read by
            // AtlasSelfConstructionToolGapBridgeService. Default-safe: the daily
            // schedule runs dry-run only; --write must be passed to persist a
            // parked proposal, and it is itself gated behind bridge_write_enabled.
            'tool_gap_bridge' => [
                'enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_ENABLED', true),
                'schedule_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_SCHEDULE_ENABLED', true),
                'schedule_time' => (string) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_SCHEDULE_TIME', '05:52'),
                // When false the scheduled run stays dry-run (observe + classify,
                // no parked proposal persisted). Operator flips on to let the
                // cadence persist parked proposals for review.
                'schedule_write_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_SCHEDULE_WRITE_ENABLED', false),
                'window_hours' => max(1, (int) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_WINDOW_HOURS', 24)),
                'min_occurrences' => max(2, (int) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_MIN_OCCURRENCES', 3)),
                'max_proposals' => max(1, (int) env('ATLAS_SELF_CONSTRUCTION_TOOL_GAP_BRIDGE_MAX_PROPOSALS', 5)),
            ],
        ],

        // G5 — governed promotion of a certified loop proposal to a NEW BRANCH
        // (never main; merge stays the operator's git/PR act). Declared HERE under
        // atlas.ai.loop because AtlasLoopProposalPromotionGate reads this exact
        // path (the runtime atlas.loop block at the root is the campaign engine's).
        'loop' => [
            'merge_to_source_enabled' => (bool) env('ATLAS_LOOP_MERGE_TO_SOURCE_ENABLED', false),
            // Merge-livre v2 (decisão do operador 11-12/06): propostas certificadas +
            // re-provadas são mergeadas EM MAIN pelo AtlasLoopAutoMergeService (commit
            // real, receipt, canário fix-forward-first). Default OFF; o operador ligou
            // em 12/06 via .env. Reversível a qualquer momento.
            'auto_merge_to_main' => (bool) env('ATLAS_LOOP_AUTO_MERGE_TO_MAIN', false),
            // L5-9 (campanhas multi-repo): o Loop pode manter os OUTROS repos do operador
            // (blackink, nivor, …) com os MESMOS guards — mas a porta de merge é POR-REPO.
            // Invariante pétreo: NEVER-MERGE DEFAULT TAMBÉM LÁ. Um repo estrangeiro só é
            // mergeável quando `enabled` está ON E o caminho ABSOLUTO canônico está em
            // `allowed_repos` (CSV no .env). Default fechado: nenhum repo estrangeiro
            // autorizado. O home repo (atlas-server) continua governado por
            // `auto_merge_to_main` — ligar multi-repo NÃO reabre a porta do home.
            'multi_repo' => [
                'enabled' => (bool) env('ATLAS_LOOP_MULTI_REPO_ENABLED', false),
                'allowed_repos' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('ATLAS_LOOP_MULTI_REPO_ALLOWED_REPOS', '')),
                ), static fn (string $s): bool => $s !== '')),
            ],
            // L3-7: cada merge real vira learning recallável (accrual de compounding,
            // contrato no-noise — só dispara em merge concreto com claim substantivo).
            'compounding_accrual' => (bool) env('ATLAS_LOOP_COMPOUNDING_ACCRUAL', true),
            // L5-11: read-only learn→recall→USE measurement over existing
            // compounding/RAG feedback rows. It never promotes memory or changes
            // retrieval; completion is claimable only when live feedback shows
            // recalled compounding memory used in passing outcomes with A/B lift.
            'learning_recall_use_lift' => [
                'enabled' => (bool) env('ATLAS_LEARNING_RECALL_USE_LIFT_ENABLED', true),
                'schedule_enabled' => (bool) env('ATLAS_LEARNING_RECALL_USE_LIFT_SCHEDULE_ENABLED', true),
                'schedule_time' => (string) env('ATLAS_LEARNING_RECALL_USE_LIFT_SCHEDULE_TIME', '06:00'),
                'min_cases_per_arm' => (int) env('ATLAS_LEARNING_RECALL_USE_LIFT_MIN_CASES_PER_ARM', 2),
                'min_passing_memory_use' => (int) env('ATLAS_LEARNING_RECALL_USE_LIFT_MIN_PASSING_MEMORY_USE', 1),
                'max_events' => (int) env('ATLAS_LEARNING_RECALL_USE_LIFT_MAX_EVENTS', 500),
            ],
            // INDEPENDÊNCIA 24h+: boot-smoke pré-commit — um merge que quebra o BOOT do app
            // (erro de lógica que passa no php -l) é rejeitado ANTES de entrar em main, senão
            // o supervisor entraria em crash-loop ao reiniciar por drift. Default ON.
            'boot_smoke_guard' => (bool) env('ATLAS_LOOP_BOOT_SMOKE_GUARD', true),
            // PRE-COMMIT CANARY GATE (default ON): the single-file merge path runs the sibling
            // canary BEFORE the commit; a RED canary reverts the apply (main untouched) + retires
            // + enqueues fix-forward, so a behavioral regression never transits main. Fix-forward
            // semantics are preserved (the loop still fixes forward, from a CLEAN main). Flip OFF
            // to restore the pure v2 policy (canary post-commit, never reverts — regression lands).
            'precommit_canary_gate' => (bool) env('ATLAS_LOOP_PRECOMMIT_CANARY_GATE', true),
            // ACDE lever #2 — FULL-COVERAGE canary. The default canary proves only the FIRST changed file
            // that has a sibling (it returns on the first match) and lets a no-sibling diff through — the
            // literal seam behind the 12 canary-RED merges that leaked (files 2..N of a multi-file diff, and
            // sibling-less source, were never exercised). When ON, the canary runs EVERY changed file's
            // sibling on ./vendor/bin/phpunit (NOT `artisan test` — its autoloader-redeclare exit-255 hazard
            // would spuriously retire good proposals once N siblings run) and fails CLOSED on ANY sibling RED.
            // Default OFF => byte-identical (first-sibling, `artisan test`).
            'canary_full_coverage' => (bool) env('ATLAS_LOOP_CANARY_FULL_COVERAGE', false),
            // ACDE lever #2 (coverage half) — only meaningful with canary_full_coverage ON: additionally block
            // any merge whose changed app/**.php source resolves NO behavioral sibling (an unprovable source
            // must not reach main). Default OFF => an uncovered source is recorded but not blocked.
            'canary_require_coverage' => (bool) env('ATLAS_LOOP_CANARY_REQUIRE_COVERAGE', false),
            // ACDE lever #2b — run the cross-suite BroaderRegressionGate on the LIVE single-file merge lane
            // (the canary proves the changed file's own sibling; this proves the affected test MODULES + boot
            // + lint). The gate is built + DI-bound but until now consumed only by the inert obra path. A
            // non-pass blocks pre-commit (undo apply, retire, fix-forward) just like a canary RED. Default OFF
            // => not called => byte-identical. Cost (runs whole suite dirs) is why it is default-OFF; arm it
            // together with broader_regression_gate_phpunit (below) and canary_full_coverage.
            'broader_regression_gate_live' => (bool) env('ATLAS_LOOP_BROADER_REGRESSION_GATE_LIVE', false),
            // L4-3: cada merge governado recebe um impact receipt determinístico
            // (categoria, tamanho, alvo real-vs-generated, score) para o guard e os
            // relatórios medirem valor, não só volume/quebra.
            'impact_receipts_enabled' => (bool) env('ATLAS_LOOP_IMPACT_RECEIPTS_ENABLED', true),
            'impact_receipts_report_limit' => (int) env('ATLAS_LOOP_IMPACT_RECEIPTS_REPORT_LIMIT', 500),
            // VALUE GATE (impact upgrade): a TIGHTENING — refuses near-zero-impact merges
            // (orphan/dead-scaffolding) at the merge boundary, AFTER all safety gates. ON
            // by default (tightening is safe to automate). Fail-safe: blocked = revert the
            // applied diff pre-commit + NOT retired (re-discoverable). Tune the floor/min
            // callers to trade throughput for impact.
            'value_gate_enabled' => (bool) env('ATLAS_LOOP_VALUE_GATE_ENABLED', true),
            'value_gate_min_impact_score' => (float) env('ATLAS_LOOP_VALUE_GATE_MIN_IMPACT_SCORE', 0.45),
            'value_gate_min_callers' => (int) env('ATLAS_LOOP_VALUE_GATE_MIN_CALLERS', 1),
            // When the wired-caller infra is UNAVAILABLE (grep error / stale world model)
            // the gate can't measure callers. Default fail-OPEN (don't block a real fix on a
            // transient blip); flip false to fail-CLOSED so a genuine orphan can't slip when
            // measurement degrades. Either way the receipt stamps `unmeasured` for the digest.
            'value_gate_fail_open' => (bool) env('ATLAS_LOOP_VALUE_GATE_FAIL_OPEN', true),
            // SUBSTANCE FLOOR (default OFF): block LOW-VALUE work from auto-merging — the operator wants
            // only enormous refactors / big obras / big features, never tiny vanilla clamps. Refactor
            // contracts (AST complexity drop already cert-gated) are EXEMPT from the size/leverage arm;
            // vanilla (non-refactor) changes must clear min_touched lines AND min_callers leverage; all
            // changes must clear the hard min_touched_floor. Fail-closed on the vanilla proof at the
            // merge boundary (blocked = re-discoverable), fail-open on unmeasured callers. OFF = today.
            'substance_floor_enabled' => (bool) env('ATLAS_LOOP_SUBSTANCE_FLOOR_ENABLED', false),
            'substance_floor_min_touched' => max(1, (int) env('ATLAS_LOOP_SUBSTANCE_FLOOR_MIN_TOUCHED', 30)),
            'substance_floor_min_callers' => max(0, (int) env('ATLAS_LOOP_SUBSTANCE_FLOOR_MIN_CALLERS', 2)),
            'substance_floor_min_touched_floor' => max(1, (int) env('ATLAS_LOOP_SUBSTANCE_FLOOR_MIN_TOUCHED_FLOOR', 10)),
            // Fix-forward routing: a canary-red regression is global, but the task queue is
            // campaign-scoped. Route the fix-forward to the freshest LIVE supervisor (running,
            // not killed, heartbeat within this window) instead of the proposal's usually-dead
            // originating campaign, so the regression is actually claimed. Generous default so a
            // campaign mid-long-grind (heartbeat only beaten between tasks) still counts as live.
            'fix_forward_live_campaign_freshness_seconds' => max(60, (int) env('ATLAS_LOOP_FIX_FORWARD_LIVE_CAMPAIGN_FRESHNESS_SECONDS', 1800)),
            // Ungameable Utility/Impact grade: window of recent merges + the hub fan-in
            // threshold that counts as compounding-leverage.
            'utility_grade_window' => (int) env('ATLAS_LOOP_UTILITY_GRADE_WINDOW', 50),
            'utility_grade_hub_callers' => (int) env('ATLAS_LOOP_UTILITY_GRADE_HUB_CALLERS', 3),
        ],

        // AP-819 AUTOPILOT — diretiva do operador 2026-06-11: evolução do harness
        // AUTOMÁTICA, gated por matemática (surface bounds + não-regressão dupla na
        // suite congelada + recorrência no outcome cru com auto-reverse). 1 edit por
        // run, 1 experimento por chave, tudo receitado e visível em GET /ai/harness.
        'harness_autopilot' => [
            'enabled' => (bool) env('ATLAS_HARNESS_AUTOPILOT_ENABLED', false),
            'observation_days' => (int) env('ATLAS_HARNESS_AUTOPILOT_OBSERVATION_DAYS', 7),
            // Anti-self-seal (L3-9 #7): the autopilot may NOT seal its own Gate-2
            // baseline unchallenged — a self-sealed baseline is a reference the
            // autopilot itself authored, so "non-regression vs baseline" becomes
            // self-judging. Default OFF (fail-closed): if no baseline is sealed the
            // run aborts with no_baseline_requires_operator_seal until an operator
            // (or explicit gate) seals one. Flip ON only to restore the old
            // auto-seal behavior deliberately.
            'allow_self_seal_baseline' => (bool) env('ATLAS_HARNESS_AUTOPILOT_ALLOW_SELF_SEAL_BASELINE', false),
        ],

        // G7 — ponte síncrona texto→resultado: POST /ai/interactions/sync roda o
        // pipeline de criação + a execução do worker INLINE no request (mesmos
        // trilhos provados; zero máquina nova). Gasto só quando o operador posta.
        'sync_bridge' => [
            'enabled' => (bool) env('ATLAS_AI_SYNC_BRIDGE_ENABLED', false),
            'max_execution_seconds' => (int) env('ATLAS_AI_SYNC_BRIDGE_MAX_EXECUTION_SECONDS', 420),
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
        // When true, a nested claude projection (whose canonical context already loads from a
        // managed ancestor CLAUDE.md) emits only its Manual Notes instead of duplicating the
        // Operating Contract / Governance / Provider-Safe Memory / Pointers blocks every turn.
        'provider_projection_lean_nested' => (bool) env('ATLAS_AI_PROVIDER_PROJECTION_LEAN_NESTED', false),
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
                // L3-3: guard de regressão — um ACP "succeeded" com output VAZIO (o sintoma
                // diff-0) é tratado como fallback-required → cai no CLI provado, com razão
                // auditável. Default ON; permite reativar o transporte acp warm com segurança.
                'acp_empty_output_fallback' => (bool) env('ATLAS_AI_HERMES_ACP_EMPTY_OUTPUT_FALLBACK', true),
                'model' => env('ATLAS_AI_HERMES_MODEL', 'gpt-5.5'),
                'model_label' => env('ATLAS_AI_HERMES_MODEL_LABEL', env('ATLAS_AI_HERMES_MODEL') ?: 'Hermes Executive Runtime'),
                'model_tier' => env('ATLAS_AI_HERMES_MODEL_TIER', 'executive_runtime'),
                'model_identity' => env('ATLAS_AI_HERMES_MODEL_IDENTITY', env('ATLAS_AI_HERMES_MODEL') ?: 'gpt-5.5'),
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
    // L3-14: campanha Fable — série diária do delta N×M (HOJE vs Marco Zero).
    'fable' => [
        'delta_series_enabled' => (bool) env('ATLAS_FABLE_DELTA_SERIES_ENABLED', true),
    ],

    'cognition' => [
        // L3-11: agenda diária do mint de pipeline receipts (sobe a dimensão mais fraca do
        // ACOS com evidência resolved, mirando os subsistemas partial). Default ON; reversível.
        'mint_pipeline_receipts_enabled' => (bool) env('ATLAS_COGNITION_MINT_PIPELINE_RECEIPTS_ENABLED', true),

        // L6-9: gate honesto para o claim "ACOS 10/10 real". Ele não cunha
        // receipts nem backfilla tempo; só permite completion quando o scorecard
        // resolved-evidence e a delta-series append-only sustentam >=30 dias.
        'acos_long_horizon_gate' => [
            'enabled' => (bool) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SCHEDULE_TIME', '06:55'),
            'min_days' => max(1, (int) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_DAYS', 30)),
            'min_overall' => max(0.0, min(10.0, (float) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_OVERALL', 9.5))),
            'min_pipeline' => max(0.0, min(10.0, (float) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_PIPELINE', 9.5))),
            // Freshness bound (calendar days): the latest delta-series day must be
            // within this window of "today" or the gate rejects it as stale. Any
            // future-dated row is always rejected. Mechanical does_not_backfill_time.
            'max_latest_stale_days' => max(0, (int) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MAX_LATEST_STALE_DAYS', 2)),
            'series_path' => (string) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SERIES_PATH', storage_path('app/atlas/evidence/fable-delta-series.jsonl')),
            'receipt_path' => (string) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/acos-long-horizon-gate.json')),
        ],

        // L6-11: predictive code intelligence completion gate. It reuses the
        // existing code-gate and predictive-failure calibration tables; it never
        // mints predictions or backfills outcomes.
        'predictive_code_intelligence_gate' => [
            'enabled' => (bool) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_SCHEDULE_TIME', '07:25'),
            'domain' => (string) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_DOMAIN', 'learning'),
            'window_days' => max(1, (int) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_WINDOW_DAYS', 60)),
            'min_outcomes' => max(1, (int) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_MIN_OUTCOMES', 3)),
            'min_failure_signature_outcomes' => max(1, (int) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_MIN_FAILURE_SIGNATURE_OUTCOMES', 1)),
            'max_avg_calibration_error' => max(0.0, min(1.0, (float) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_MAX_AVG_CALIBRATION_ERROR', 0.35))),
            'max_brier_score' => max(0.0, min(1.0, (float) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_MAX_BRIER_SCORE', 0.25))),
            'max_code_index_age_minutes' => max(1, (int) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_MAX_CODE_INDEX_AGE_MINUTES', 1440)),
            'auto_refresh' => (bool) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_AUTO_REFRESH', false),
            'receipt_path' => (string) env('ATLAS_COGNITION_PREDICTIVE_CODE_INTELLIGENCE_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/predictive-code-intelligence-gate.json')),
        ],

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

    'compounding' => [
        // L6-13: fixed-N capability-per-dollar gate. Measures only local
        // wrapper capability/cost; never estimates external provider capability.
        'fixed_n_capability_dollar_gate' => [
            'enabled' => (bool) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_SCHEDULE_TIME', '07:35'),
            'series_path' => (string) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_SERIES_PATH', storage_path('app/atlas/evidence/fixed-n-capability-dollar-series.jsonl')),
            'receipt_path' => (string) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/fixed-n-capability-dollar-gate.json')),
            'fixed_provider' => (string) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_FIXED_PROVIDER', 'codex'),
            'fixed_model' => (string) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_FIXED_MODEL', 'gpt-5.5'),
            'min_days' => max(1, (int) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_MIN_DAYS', 30)),
            'min_cost_coverage_pct' => max(0.0, min(100.0, (float) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_MIN_COST_COVERAGE_PCT', 80))),
            'min_measured_cost_days' => max(1, (int) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_MIN_MEASURED_COST_DAYS', 30)),
            'min_positive_trend_delta' => (float) env('ATLAS_COMPOUNDING_FIXED_N_CAPABILITY_DOLLAR_GATE_MIN_POSITIVE_TREND_DELTA', 0.0001),
        ],
    ],

    'long_horizon' => [
        // L6-12: explicit writer for provider-safe continuity packs. It feeds
        // the existing read-only continuity certifier and replay manifest builder.
        'continuity_pack_emitter' => [
            'enabled' => (bool) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_SCHEDULE_TIME', '07:30'),
            'scope_type' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_SCOPE_TYPE', 'long_horizon'),
            'scope_id' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_SCOPE_ID', 'fable-lista-6'),
            'evidence_root' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_EVIDENCE_ROOT', storage_path('app/atlas/evidence')),
            'doc_path' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_DOC_PATH', base_path('docs/fable-lista-6-14-itens.md')),
            'max_evidence_refs' => max(2, (int) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_MAX_EVIDENCE_REFS', 40)),
            'stale_after_days' => max(1, (int) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_STALE_AFTER_DAYS', 21)),
            'required_evidence_kinds' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_REQUIRED_EVIDENCE_KINDS', 'doc,artifact'))))),
            'strict_replay' => (bool) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_STRICT_REPLAY', true),
            'receipt_path' => (string) env('ATLAS_LONG_HORIZON_CONTINUITY_PACK_EMITTER_RECEIPT_PATH', storage_path('app/atlas/evidence/long-horizon-continuity-pack.json')),
        ],

        // L6-12 keystone: prove CROSS-WEEK continuity with a measured old-memory
        // recall lift on REAL elapsed calendar time. The emitter above proves
        // resume-readiness now; this gate proves the DoD ("recall de uma decisão
        // de 3 semanas atrás influencia uma certificação de hoje, medido"). It is
        // read-only, fail-closed and never fabricates elapsed time / recall
        // events / lift — every age derives from the real persisted created_at.
        // It auto-greens the instant real >=3-week-old recall data exists.
        'cross_week_recall_lift_gate' => [
            'enabled' => (bool) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_SCHEDULE_TIME', '07:32'),
            'scope_type' => (string) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_SCOPE_TYPE', 'long_horizon'),
            'scope_id' => (string) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_SCOPE_ID', 'fable-lista-6'),
            'min_recall_age_days' => max(1, (int) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_MIN_RECALL_AGE_DAYS', 21)),
            'recent_window_days' => max(1, (int) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_RECENT_WINDOW_DAYS', 7)),
            'min_calendar_span_days' => max(1, (int) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_MIN_CALENDAR_SPAN_DAYS', 21)),
            'min_recall_events' => max(1, (int) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_MIN_RECALL_EVENTS', 1)),
            'min_new_tasks' => max(1, (int) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_MIN_NEW_TASKS', 1)),
            'min_recall_lift' => max(0.0, (float) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_MIN_RECALL_LIFT', 0.01)),
            'receipt_path' => (string) env('ATLAS_LONG_HORIZON_CROSS_WEEK_RECALL_LIFT_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/long-horizon-cross-week-recall-lift-gate.json')),
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
        // L5-6: ADML cost×outcome routing. This is not a parallel router:
        // activation still goes through the existing Atlas Decide routing
        // table + operator receipt, and gateway fallback remains intact.
        'adml_cost_outcome' => [
            'enabled' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED', false),
            'min_evidence' => max(1, (int) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_EVIDENCE', 3)),
            'min_certification_rate' => max(0.0, min(1.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_CERTIFICATION_RATE', 0.8))),
            'min_score' => max(0.0, min(100.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_SCORE', 80.0))),
            'max_score_drop' => max(0.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MAX_SCORE_DROP', 3.0)),
            'require_measured_cost' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_REQUIRE_MEASURED_COST', true),
            'min_cost_samples' => max(1, (int) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_COST_SAMPLES', 1)),
        ],
        'adml_provider_aliases' => [
            'anthropic_claude' => 'claude_cli',
            'claude' => 'claude_cli',
            'claude_code' => 'claude_cli',
            'openai_codex' => 'codex_cli',
            'openai_gpt' => 'codex_cli',
            'codex' => 'codex_cli',
            'minimax' => 'minimax_m27_cli',
            'minimax_m3' => 'minimax_m27_cli',
            'minimax-m3' => 'minimax_m27_cli',
            'minimax_m27' => 'minimax_m27_cli',
            'hermes' => 'hermes_cli',
            'google_gemini' => 'gemini_cli',
            'gemini' => 'gemini_cli',
        ],
        'swarm_production_resolver_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_PRODUCTION_RESOLVER_ENABLED', false),
        'swarm_parallel_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_PARALLEL_ENABLED', false),
        'swarm_auto_failover_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_AUTO_FAILOVER_ENABLED', false),
        'swarm_circuit_threshold' => (int) env('ATLAS_PATAMAR4_SWARM_CIRCUIT_THRESHOLD', 3),
        'swarm_circuit_cooldown_seconds' => (int) env('ATLAS_PATAMAR4_SWARM_CIRCUIT_COOLDOWN_SECONDS', 60),
        // L6-10: shadow-only multi-agent topology auto-composer. Selects a
        // bounded plan topology by task type and measures convergence through
        // the existing governed conductor; it never changes live routing.
        'swarm_topology_auto_composer' => [
            'enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_SCHEDULE_TIME', '07:20'),
            'min_task_types' => max(2, (int) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_MIN_TASK_TYPES', 2)),
            'min_distinct_topologies' => max(2, (int) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_MIN_DISTINCT_TOPOLOGIES', 2)),
            'min_convergence_rate' => max(0.0, min(1.0, (float) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_MIN_CONVERGENCE_RATE', 1.0))),
            'forced_provider' => (string) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_FORCED_PROVIDER', 'codex'),
            'forced_model' => (string) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_FORCED_MODEL', 'gpt-5.5'),
            'receipt_path' => (string) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_AUTO_COMPOSER_RECEIPT_PATH', storage_path('app/atlas/evidence/swarm-topology-auto-compose.json')),
        ],
        // L6-10 live wiring: when ON, the SHARED AtlasSwarmTopologySelector drives
        // the live AtlasEngineeringRunConductorService dispatch — it auto-composes
        // the topology-by-task-type plan-DAG and routes it through the governed
        // runPlan() path (per-node Kernel + Admission + whole-plan pre-gate). It
        // changes ordering/shape of EXISTING dispatch nodes only; it never escalates
        // the sovereignty mode guard and never spends a provider token on its own.
        // DEFAULT OFF, fail-open: with the flag off the conductor's single-dispatch
        // path is byte-for-byte unchanged. Flip only after the wiring is proven.
        'swarm_topology_live_routing_enabled' => (bool) env('ATLAS_PATAMAR4_SWARM_TOPOLOGY_LIVE_ROUTING_ENABLED', false),
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
        // L2-10 (dívida do sweep O-1): quando ON, a validação governada só aceita um test
        // runner REAL (php artisan test --filter / phpunit --filter), rejeitando `php -r`
        // livre (gameável: `exit(0)` carimba verde sem provar nada). Default OFF porque
        // callers legítimos ainda usam `php -r` como marker de smoke — ligar exige migrá-los.
        'validation_test_runner_only' => (bool) env('ATLAS_FORGE_VALIDATION_TEST_RUNNER_ONLY', false),
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
        // Lever 4 — CROSS-PROVIDER best-of-N. Comma-separated provider keys the explorer rotates the N
        // attempts across, so candidates are DECORRELATED by engine (codex and MiniMax fail differently),
        // a real one-shot success-rate lift. Empty => single-provider (byte-identical to before). The
        // explorer reads this ONLY when a task pins no provider, so pure unit tests never touch config.
        'scenario_provider_portfolio' => array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', (string) env('ATLAS_LOOP_SCENARIO_PROVIDER_PORTFOLIO', '')),
        ), static fn (string $p): bool => $p !== '')),
        // ACDE direction-(a) · DEEPER best-of-N on a SINGLE weak engine. The cross-provider portfolio
        // above decorrelates by ENGINE; this is its single-engine sibling. On Hermes/MiniMax there is no
        // per-call temperature/seed, so the ONLY decorrelation lever is STRATEGY diversity. The explorer's
        // built-in pool is 5 mandates (baseline + A..D), but max_scenarios_per_task widens to 12 — so
        // scenarios 5..11 cycle back to the same 5 prompts and, lacking temp/seed, collapse into near-
        // duplicate diffs (best-of-12 buys only best-of-5-distinct). When ON, the explorer's default pool
        // extends to 9 STRUCTURALLY-DISTINCT mandates (adds guard-first / extract-helper / type-driven /
        // invert-flatten) so widening actually buys genuinely-different candidates the frozen judge can
        // choose between. Injected via the task by the grinder (the explorer hot path stays config-free);
        // indices 0..4 are byte-identical so OFF == today and persisted strategy keys are stable.
        'deep_strategy_portfolio' => (bool) env('ATLAS_LOOP_DEEP_STRATEGY_PORTFOLIO', false),
        // ACDE lever #1 — the SAFETY belt for arming the multi-engine best-of-N portfolio. The cross-
        // provider rotation (scenario_provider_portfolio above) is fully built + live on Path B, but agentic
        // CLI engines (codex_cli/gemini_cli) edit the workspace IN PLACE — so the FrozenJudge SCOPE guard must
        // be real. Without allowed_globs the judge defaults to ['**'] (allow-all) and an out-of-scope edit by
        // a rotated engine is NOT caught. When this is ON, the materializers derive the writable globs from
        // allowed_files (the tightest correct scope) for any task whose acceptance carries none — so the
        // portfolio can be armed soundly. ARMING BUNDLE (operator, .env): set this true AND set
        // ATLAS_LOOP_SCENARIO_PROVIDER_PORTFOLIO=hermes_cli,codex_cli,gemini_cli (keys MUST be the *_cli form
        // — bare 'codex'/'gemini' hit provider_not_configured and silently no-op). Default OFF => byte-
        // identical (tightening to allowed_files never false-rejects a candidate that edits only what it may).
        'cross_provider_best_of_n' => (bool) env('ATLAS_LOOP_CROSS_PROVIDER_BEST_OF_N', false),
        // ACDE lever #5 — the strong-engine ESCALATION rung (the missing N×M multiplier). The conductor's
        // ladder walks best_of_n -> repair -> decompose -> escalate_provider; on a single weak engine that
        // last tier just re-ran the SAME engine wider, so a no-winner dead-ended (a DQS refusal=defect). Set
        // this to a genuinely STRONGER configured engine key (e.g. codex_cli / a gpt-5.5 provider) and the
        // FINAL rung hands that engine the now-refuted task under the SAME pétreo frozen judge — converting a
        // refusal into a certified delivery on the same run. NEVER Claude (Anthropic 3rd-party block + no-burn
        // rule) and never equal to the weak default (anti-theatre); both fall back to byte-identical. Default
        // '' => OFF => byte-identical (the rung stays the same-engine $deep tier).
        'escalation_strong_provider' => trim((string) env('ATLAS_LOOP_ESCALATION_STRONG_PROVIDER', '')),
        // Hard caps per task (the autoresearch fixed-budget discipline).
        'max_seconds_per_scenario' => max(30, (int) env('ATLAS_LOOP_MAX_SECONDS_PER_SCENARIO', 600)),
        // The loop NEVER merges to main: it accumulates certified-for-review proposals.
        'propose_only' => (bool) env('ATLAS_LOOP_PROPOSE_ONLY', true),

        // L2-1: "sucesso" de provider com ZERO mudanças no workspace re-tenta uma vez
        // (assinatura da regressão acp diff-0); carimbo zero_diff_retry auditável.
        'zero_diff_retry' => (bool) env('ATLAS_LOOP_ZERO_DIFF_RETRY', true),

        // ACDE Tier-0 #4: probe determinístico de overfit no cert pipeline — recusa `return <literal>`
        // curto-circuitado por func_num_args() ou igualdade-no-input (o gaming do modelo fraco). Alta
        // precisão (só as formas inequívocas), gateia TODA cert. Default ON: pegar isso É o moat.
        'overfit_probe_enabled' => (bool) env('ATLAS_LOOP_OVERFIT_PROBE_ENABLED', true),

        // ACDE engine-independence: um provider de TEXTO/HTTP (MiniMax M3) não edita arquivos
        // — devolve a mudança como unified diff no corpo da resposta. Com este flag ON, o
        // WorkspaceProviderLoopExecutionDriver parseia e APLICA esse diff no workspace via o
        // caminho provado DiffParser+PatchApplier, transformando um motor de texto em agente
        // que edita repo. Guardado por changed_files===[] (CLI providers não são afetados).
        // Default ON: é a capacidade que destrava rodar o loop no motor mais fraco/barato.
        'text_provider_edit_apply' => (bool) env('ATLAS_LOOP_TEXT_PROVIDER_EDIT_APPLY', true),

        // ACDE Tier-1 #7: DEPENDENCY-BODY grounding. The code-graph seam injects only callee SIGNATURES;
        // a weak engine then hallucinates the callee CONTRACT (confident wrong calls). With this ON, the
        // driver appends the EXACT body (line_start..line_end range read, capped) of the top-K cross-file
        // dependencies. Default OFF => byte-identical prompt.
        'inject_dependency_bodies' => (bool) env('ATLAS_LOOP_INJECT_DEPENDENCY_BODIES', false),
        // ACDE lever B1-fast — extend the brain-context seams (code-graph signatures + dependency bodies)
        // to the ITERATE-TO-GREEN retry prompt (buildFixPrompt), not just the first attempt. Today the
        // retry — exactly where the weak engine is failing — goes in with ZERO brain. When ON (AND the
        // per-source flags above are armed), the fix prompt gets the same grounding as the initial prompt.
        // Default OFF => byte-identical; injects nothing the operator has not already armed for buildPrompt.
        'brain_context_on_fix_prompt' => (bool) env('ATLAS_LOOP_BRAIN_CONTEXT_ON_FIX_PROMPT', false),
        // ACDE B3 — provider-safe DELIVERY RECALL into the loop prompt window: the recent CERTIFIED merged
        // deliveries in the edited file's module (paths only, never raw code), so the weak engine matches the
        // conventions of what just landed nearby. The read-back half of the brain flywheel whose write half is
        // the merge itself (reads atlas_loop_proposals — no new table, no merge-path write). A SELF signal,
        // never engine-vs-engine. Default OFF => no lines => byte-identical.
        'brain_delivery_recall_enabled' => (bool) env('ATLAS_LOOP_BRAIN_DELIVERY_RECALL_ENABLED', false),
        // ACDE B4a — inject the BLAST RADIUS of the edited file (who depends on it, via the code-graph reverse-
        // dependency walk) so the weak engine edits a hub knowingly. De-orphans AtlasLoopBlastRadiusAnalyzer
        // over the live world-model edges; surfaces consumer PATHS + a risk band only (provider-safe, no raw
        // code). Reads an index that already exists (no new table, no write). Default OFF => no lines =>
        // byte-identical. Arm only when the workspace code-graph index is fresh (else no path match => empty).
        'blast_radius_brain_enabled' => (bool) env('ATLAS_LOOP_BLAST_RADIUS_BRAIN_ENABLED', false),
        // ACDE B2 — AGGREGATE cap (total chars across ALL in-scope files) for the CURRENT FILE CONTENTS dump
        // in the loop prompt. Each file is capped individually, but with no total cap a many-file obra swamps
        // the weak engine's small window before the ranked brain context lands. 0 (default) => unlimited =>
        // byte-identical; set e.g. 40000 to protect the MiniMax window (overflow files are omitted with the
        // [TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET] marker). Pair with code_graph.auto_context_budget (now read).
        'prompt_file_contents_budget_chars' => max(0, (int) env('ATLAS_LOOP_PROMPT_FILE_CONTENTS_BUDGET_CHARS', 0)),

        // L2-2: descoberta admite targets framework-reach (serviços REAIS) — eles seguem
        // o caminho framework-materializer + intent-verifier + certificação adversarial.
        // Default OFF de fábrica; o operador ligou em 12/06 (.env). Reversível.
        'discovery_framework_targets' => (bool) env('ATLAS_LOOP_DISCOVERY_FRAMEWORK_TARGETS', false),

        // L3-2: a descoberta injeta intents guiados pelo BACKLOG REAL (manifesto curado +
        // corpus de falhas), com OBJETIVO ESPECÍFICO promovido no ranking — o que ataca os
        // 94% de waste medidos no Marco Zero (o Loop deixa de inventar tarefa genérica).
        // Default OFF de fábrica; o operador liga via .env. Fail-open (fonte vazia ⇒ no-op).
        'discovery_backlog_intents' => (bool) env('ATLAS_LOOP_DISCOVERY_BACKLOG_INTENTS', false),

        // L4-1: ranking anti-Goodhart. O score estrutural continua sendo a base, mas ganha
        // boost limitado por impacto real: surface no code graph, evidência de falha e backlog.
        'impact_ranking_enabled' => (bool) env('ATLAS_LOOP_IMPACT_RANKING_ENABLED', true),

        // WIRED targeting (impact upgrade): demote/exclude orphan scaffolding (0 real
        // production callers + 0 failure evidence + 0 backlog reach) so the provider
        // budget hardens code that RUNS. Default ON (deprioritise); hard_exclude OFF.
        'orphan_gate_enabled' => (bool) env('ATLAS_LOOP_ORPHAN_GATE_ENABLED', true),
        'orphan_score_penalty' => (float) env('ATLAS_LOOP_ORPHAN_SCORE_PENALTY', 0.15),
        'orphan_gate_hard_exclude' => (bool) env('ATLAS_LOOP_ORPHAN_GATE_HARD_EXCLUDE', false),

        // SUBSTANTIVE-GRIND (lift NON_TRIVIAL): route the grind to WIRED files that already
        // carry a convention sibling test, frame the objective as the sibling coverage gap,
        // and make a canary-green substantive change the credit. ALL default OFF/fail-open
        // so the running 24/7 loop is byte-identical until the operator flips them.
        'test_gap_targets' => (bool) env('ATLAS_LOOP_TEST_GAP_TARGETS', false),
        'prefer_test_backed_targets' => (bool) env('ATLAS_LOOP_PREFER_TEST_BACKED_TARGETS', false),
        'substantive_tiebreak' => (bool) env('ATLAS_LOOP_SUBSTANTIVE_TIEBREAK', false),
        'test_gap_min_callers' => max(0, (int) env('ATLAS_LOOP_TEST_GAP_MIN_CALLERS', 1)),
        'test_gap_hub_callers' => max(2, (int) env('ATLAS_LOOP_TEST_GAP_HUB_CALLERS', 3)),

        // GOVERNED REFACTOR (Phase 1 within-file). The loop can synthesize a
        // `refactor_reduce_complexity` objective for a high-complexity self-contained file
        // that HAS a real sibling test, and the frozen judge certifies ONLY when the frozen
        // sibling test stays GREEN (behavior preserved — the loop cannot edit it) AND a real
        // AST cyclomatic measure DROPS. ALL FOUR default OFF; with them OFF the judge,
        // discovery and refiller are byte-identical to today (proven by a default-inert
        // frozen test). Each is a deliberate operator flip via .env; a separate soak step
        // turns them on. NEVER weakens never-merge / petreo HarnessGuard / canary / value-gate.
        //
        // Gates the refactor objective synthesizer at AtlasLoopQueueRefiller::generateAndEnqueue.
        'refactor_objectives_enabled' => (bool) env('ATLAS_LOOP_REFACTOR_OBJECTIVES_ENABLED', false),
        // Gates the small (<=0.12) refactor_leverage rank boost in applyImpactRanking;
        // the leverage signal is computed but UNUSED in scoring when OFF (byte-identical order).
        'refactoring_targets_enabled' => (bool) env('ATLAS_LOOP_REFACTORING_TARGETS_ENABLED', false),
        // Gates the judge's complexityEarned proof; when OFF the judge ignores complexity_proof
        // entirely and behaves exactly as today (the petreo TAMPER/SCOPE/RE-PROOF/DIFF-EARNED path).
        'refactor_complexity_proof' => (bool) env('ATLAS_LOOP_REFACTOR_COMPLEXITY_PROOF', false),
        // Gates Phase-2 routing of >=2-file refactor tasks through AtlasLoopObraBridgeService
        // (operator-reviewed, never-merge). Phase 1 is single-file only; this stays OFF until Phase 2.
        'refactor_multi_file_via_obra' => (bool) env('ATLAS_LOOP_REFACTOR_MULTI_FILE_VIA_OBRA', false),

        // FRAMEWORK REFACTOR (heavy, behavior-preserving, framework-reach targets). The loop can
        // emit a `refactor_reduce_complexity` objective for a HIGH-COMPLEXITY framework service that
        // is WIRED (>=1 real production caller) AND has real PHPUnit tests; the framework path runs
        // those REAL tests (behavior preserved) AND the semantic certifier proves an AST max-per-method
        // cyclomatic DROP (file total not increasing) by the judge's OWN measure in the gate workspace
        // — ungameable: behavior by the real tests, complexity by AST, never provider-claimed. ALL
        // default OFF: with the flag OFF the framework branch is byte-identical to today (edge-gap
        // objective only). NEVER weakens never-merge / petreo HarnessGuard / governed door /
        // broader-regression gate. Heavy multi-statement diffs are NOT rejected by any small-diff cap
        // for refactor objectives — the certification measure is complexity drop, not diff size.
        'framework_refactor_enabled' => (bool) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_ENABLED', false),
        // Cert-integrity lock for a future structural (extract-to-new-file) lane: a net-new candidate
        // file has no baseline worst-method, so its complexity win is UNPROVABLE. Default ON fails the
        // complexity verdict closed for any net-new file (blocks "god method relocated intact + cosmetic
        // drop elsewhere = certify"). Byte-identical for all existing single-file/sibling paths (they
        // never emit a net-new file in the census). Set false only to restore the legacy treat-as-no-change.
        'complexity_new_file_fail_closed' => (bool) env('ATLAS_LOOP_COMPLEXITY_NEW_FILE_FAIL_CLOSED', true),
        // Structural-depth (extract-class) lane gate (default OFF). When ON, a structural_proof
        // contract routes the complexity verdict to the per-method-identity gate
        // (structuralComplexityReduced), which supersedes the per-file-max + new-file-lock so a
        // legitimate new class file is provable — under the anti-relocation invariant (a method moved
        // intact earns nothing). OFF -> a structural_proof task falls back to complexityReduced (the
        // new-file-lock rejects the extract-class), so judge + certifier are byte-identical.
        'complexity_method_identity_gate' => (bool) env('ATLAS_LOOP_COMPLEXITY_METHOD_IDENTITY_GATE', false),
        // Characterization flywheel: max grind attempts per coverage gap before backing off. The
        // provider's test output is variable (a gap can certify on retry), so a re-detected gap gets
        // bounded retries (salted dedupe); past this it is provider-hard and the feeder stops hammering.
        'characterization_max_attempts_per_gap' => max(1, (int) env('ATLAS_LOOP_CHARACTERIZATION_MAX_ATTEMPTS_PER_GAP', 3)),
        // Min AST max-per-method cyclomatic for a framework target to be worth a heavy refactor.
        'framework_refactor_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_MIN_CYCLOMATIC', 10)),
        // WORK-SUPPLY keystone: discovery resolves real callers only for the top-N by cheap
        // structural score (caller_resolve_cap, cost guard). That starved the heavy-refactor lane —
        // a complex/wired file below that cut never got measured, never earned the promotion that
        // reaches the claim window, so refactor supply drained to ~0 and vanilla flooded. This ALSO
        // measures the top-N most-COMPLEX candidates (cyclomatic >= framework_refactor_min_cyclomatic),
        // breaking the chicken-and-egg without lowering any value gate. 0 => byte-identical legacy.
        'discovery_caller_resolve_cap' => max(1, (int) env('ATLAS_LOOP_DISCOVERY_CALLER_RESOLVE_CAP', 60)),
        'discovery_refactor_resolve_cap' => max(0, (int) env('ATLAS_LOOP_DISCOVERY_REFACTOR_RESOLVE_CAP', 40)),
        // ACDE T2 (supply-rate coupling) — multiplies BOTH discovery resolve caps so candidate SUPPLY scales
        // with scenario fan-out WIDTH (else width starves on too few candidates; supply, not width, is the
        // real bottleneck). Default 1 => byte-identical (60/40). Arm alongside scenario_fanout (e.g. 3 for
        // a width-4 fan-out). A one-time starvation-unblock, not a dynamic controller (see T3, deferred).
        'discovery_supply_widen_factor' => max(1, (int) env('ATLAS_LOOP_DISCOVERY_SUPPLY_WIDEN_FACTOR', 1)),
        // Min real production callers (wired requirement): a refactor only pays back on code that runs.
        'framework_refactor_min_callers' => max(1, (int) env('ATLAS_LOOP_FRAMEWORK_REFACTOR_MIN_CALLERS', 1)),
        // Gates the obra-auto-merge crossing (AtlasLoopObraAutoMergeService): a GENUINELY
        // certified obra branch auto-merges to main WITHOUT operator review, but ONLY after the
        // broader-regression gate passes. DEFAULT FALSE; OFF => obra always stays operator-review.
        'obra_auto_merge_enabled' => (bool) env('ATLAS_LOOP_OBRA_AUTO_MERGE_ENABLED', false),
        // PARK-FIRST MATURITY INTERLOCK (day-2 hardening). Even with the crossing flag ON, an obra
        // may auto-merge ONLY if its derived change CLASS has EARNED autonomy from REAL merge
        // history on the single-source AtlasChangeClassTrustLadder (operator-allowlisted class +
        // proven clean streak). DEFAULT TRUE => a never-proven class (e.g. any `code` obra) PARKS
        // for the operator however green its gates — autonomy is earned, never granted on a first
        // run. The operator may set FALSE for a controlled experiment, but the safe default stands.
        'obra_auto_merge_require_trust' => (bool) env('ATLAS_LOOP_OBRA_AUTO_MERGE_REQUIRE_TRUST', true),

        // OBRA CANDIDATE PRODUCER (AtlasLoopObraClusterDetectorService): connects the live grind
        // loop to the operator-gated big-obra surface. After discovery+enqueue it scans the
        // claimed targets for a WIRED high-leverage HUB (measured callers + complexity + leverage),
        // resolves the hub's REAL production caller FILE PATHS, and parks a >=2-file obra CANDIDATE
        // in the SAME operator-review backlog the architecture proposer uses. PROPOSAL-ONLY: never
        // enqueues a loop task, never calls a provider, never mutates code, never merges, never
        // weakens never-merge / HarnessGuard / the L4-10 gate. DEFAULT-OFF => fully inert. Fail-open.
        'obra_cluster_detection_enabled' => (bool) env('ATLAS_LOOP_OBRA_CLUSTER_DETECTION_ENABLED', false),
        // Min MEASURED production callers for a target to qualify as a refactor-worthy hub.
        'obra_cluster_min_callers' => max(2, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MIN_CALLERS', 3)),
        // Min AST max-per-method cyclomatic for the hub (only complex hubs are worth an obra).
        'obra_cluster_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MIN_CYCLOMATIC', 10)),
        // Min refactor_leverage (0.6*callerLeverage + 0.4*complexityLeverage) for the hub.
        'obra_cluster_leverage_floor' => max(0.0, (float) env('ATLAS_LOOP_OBRA_CLUSTER_LEVERAGE_FLOOR', 0.5)),
        // Hard cap on cluster size (hub + callers) so an obra candidate stays reviewable.
        'obra_cluster_max_files' => max(2, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MAX_FILES', 8)),
        // Re-proposal cooldown for the SAME cluster hash (anti-spam; dedup lives in the detector's
        // own durable index, NOT the backlog which mints a fresh id per call).
        'obra_cluster_cooldown_hours' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_COOLDOWN_HOURS', 168)),
        // Max obra candidates parked per refill cycle (bounds operator-queue growth).
        'obra_cluster_max_candidates_per_cycle' => max(1, (int) env('ATLAS_LOOP_OBRA_CLUSTER_MAX_CANDIDATES_PER_CYCLE', 2)),

        // DECISION work-shape router (AtlasLoopWorkShapeRouter): reasons the highest-leverage work
        // SHAPE per target from the already-stamped discovery signals, replacing the static flag
        // cascade. Load-bearing decision = work_skip (defer a CONFIRMED orphan instead of spending
        // a provider call on dead code). Default ON; fail-open to the cascade. The shape only
        // routes; the synthesizer/generator RED-gates remain the authority.
        'decision_router_enabled' => (bool) env('ATLAS_LOOP_DECISION_ROUTER_ENABLED', true),
        'decision_min_refactor_cyclomatic' => max(1, (int) env('ATLAS_LOOP_DECISION_MIN_REFACTOR_CYCLOMATIC', 10)),
        'decision_leverage_floor' => max(0.0, (float) env('ATLAS_LOOP_DECISION_LEVERAGE_FLOOR', 0.6)),

        // DECISION ("o quê a seguir") — the UNGAMEABLE next-work priority. When ON, the task
        // priority becomes BAND(shape) + OFFSET(leverage re-resolved FRESH from git/graph) instead
        // of the stored `score*100` scalar, so SHAPE dominates (a confirmed orphan can never out-rank
        // a wired hub) and no forged stored score can buy the next-work slot. Re-resolution runs at
        // ENQUEUE only (never the hot claim path); fail-open to the legacy value WITHIN the band.
        // Default OFF: with it off the enqueue priority is byte-identical to today (score*100).
        'decision_priority_enabled' => (bool) env('ATLAS_LOOP_DECISION_PRIORITY_ENABLED', false),
        // When BOTH fresh leverage reads (grep callers + AST cyclomatic) are unavailable, the offset
        // falls back to the stored score but is CAPPED to this ceiling (far below the band width)
        // so any genuinely measured target sorts strictly above any fail-open-degraded one — a
        // forged/stale stored score can never buy the next-work slot. Anti-gaming floor.
        'decision_unmeasured_offset_ceiling' => max(0, min(999, (int) env('ATLAS_LOOP_DECISION_UNMEASURED_OFFSET_CEILING', 199))),

        // OPTION 3 — operator-gated autonomous MULTI-FILE refactor. When ON (AND
        // refactor_multi_file_via_obra ON, AND obra_cluster_detection_enabled ON), the refiller
        // synthesizes a >=2-file refactor_reduce_complexity task from a detected cluster; the
        // grinder hard-routes it to the obra bridge -> operator-review. NEVER auto-merges (operator
        // approval required for every multi-file merge). DEFAULT-OFF. The CO-GATE is load-bearing:
        // with refactor_multi_file_via_obra OFF the lane is inert (a multi-file task with no obra
        // route is never admissible), so a multi-file diff can never reach main via a single-file canary.
        'multi_file_refactor_objectives_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_OBJECTIVES_ENABLED', false),

        // OPTION 3 · #7 EXECUTION — when ON, the grinder's multi-file refactor lane (already gated on
        // refactor_multi_file_via_obra) EXECUTES the obra itself when no operator L4-10 is supplied:
        // AtlasLoopObraExecutionAdapter runs the real provider on an ISOLATED worktree, certifies, and
        // produces the real L4-10, then routes the certified result to the obra bridge -> PARK for
        // operator review. A provider failure / non-real / non-certified result loops back honestly
        // (never parks). DEFAULT-OFF: with it off the lane only accepts an operator-supplied L4-10
        // (today's behaviour, byte-identical). Auto-merge of the parked obra stays a SEPARATE, default-
        // OFF decision (obra_auto_merge_enabled) — this flag only makes the loop PRODUCE the obra.
        'multi_file_execution_enabled' => (bool) env('ATLAS_LOOP_MULTI_FILE_EXECUTION_ENABLED', false),
        // PATH B (the operator-chosen unblock for BIG refactors): instead of the heavy Obra/L4-10
        // machine, let multi-file refactors run through the PROVEN normal grind. When ON: (1) the
        // refiller escalates a target with cyclomatic >= extract_class_min_cyclomatic to a 2-file
        // extract-class objective (target + a new <Target>Support.php), and (2) the grinder routes
        // multi-file refactors to the normal materializer/explorer/structural-cert (NOT the Obra
        // bridge). Safety: the structural_proof cert (cross-file census + anti-relocation), the frozen
        // sibling test, and allowed_globs (diff bounded to exactly the 2 declared files) gate the merge
        // — no Obra ceremony. Default OFF = byte-identical (single-file in-place reduction only).
        'multi_file_refactor_via_normal_lane' => (bool) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_VIA_NORMAL_LANE', false),
        'extract_class_min_cyclomatic' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_MIN_CYCLOMATIC', 15)),
        // ADEP keystone — ITERATE-TO-GREEN: after the provider attempt, the LOOP runs the acceptance
        // test and, on red, re-invokes the provider WITH the exact failure until green or budget (the
        // test-fix-retest loop codex/Claude use; the loop's single-shot lane never had it). Default
        // OFF => byte-identical (one invocation + zero-diff retry only). Token cost is no concern;
        // quality is — set the budget high. iterate_to_green_max = max re-invocations per attempt.
        'iterate_to_green_enabled' => (bool) env('ATLAS_LOOP_ITERATE_TO_GREEN_ENABLED', false),
        'iterate_to_green_max' => max(1, (int) env('ATLAS_LOOP_ITERATE_TO_GREEN_MAX', 3)),

        // ACDE Tier-0 #2: when ON, iterate-to-green's green check IS the frozen judge (diff-earned /
        // scope / frozen / complexity), not a raw exit-0 a gamed candidate can satisfy — so the loop
        // re-prompts toward a CERTIFIABLE result and feeds the real rejection reason back. Default OFF:
        // the judge re-proof per iteration roughly doubles per-iteration test cost (revert-recheck runs
        // the suite twice); arm after measuring throughput on the live engine.
        'iterate_against_judge' => (bool) env('ATLAS_LOOP_ITERATE_AGAINST_JUDGE', false),
        // ≥9 QUALITY BAR (AtlasLoopQualityGrader). The certifier always RECORDS the 0-10 grade in the
        // receipt (observability); quality_bar_gate_enabled makes it a GATE (a verified refactor below
        // the bar is refuted). Default OFF => byte-identical (grade computed, never gates). quality_bar
        // is the threshold (operator directive: 9). Raise once the loop reliably clears it.
        'quality_bar_gate_enabled' => (bool) env('ATLAS_LOOP_QUALITY_BAR_GATE_ENABLED', false),
        'quality_bar' => (float) env('ATLAS_LOOP_QUALITY_BAR', 9.0),

        // ITEM10 — FEATURE-LANE ≥9 quality bar + CONFIDENCE CALIBRATION flywheel. The certifier always
        // RECORDS gradeFeature()'s 0-10 score on a non-refactor cert; delivery_bar.armed turns it into a
        // GATE (a feature below the bar is refuted). The auto-merge feeder appends {predicted,correct}
        // samples post-merge; atlas:loop:confidence-calibrate fits the honest arm-threshold. All default
        // OFF => byte-identical. Note: mutation SAMPLING runs regardless of the mutation-gate flag, so a
        // genuinely good feature (behavior preserved + diff-earned + frozen suite kills its sampled
        // mutants) scores ~9.5 and PASSES the 9.0 bar; only an undiff-earned change or a weak frozen suite
        // (mutants survive) scores below 9 and is refused — arming this does NOT universally refuse features.
        'delivery_bar' => [
            'armed' => (bool) env('ATLAS_LOOP_DELIVERY_BAR_ARMED', false),
        ],
        'confidence_calibration' => [
            'enabled' => (bool) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_ENABLED', false),
            'target_precision' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_TARGET', 0.93))),
            'min_samples' => max(1, (int) env('ATLAS_LOOP_CONFIDENCE_CALIBRATION_MIN_SAMPLES', 20)),
        ],
        // LEVER 2 — CALIBRATED delivery confidence. The certifier RECORDS a principled P(correct) over the
        // cert's measurable signals on every proposal (replacing the old hardcoded confidence theatre). The
        // GATE (confidence_gate_enabled) refuses a cert below the threshold — but arming it at a TRUSTWORTHY
        // 0.93 is honest only AFTER the self-calibration loop fits the weights to real outcomes, so it ships
        // OFF (record-only). weights/threshold are config so calibration can refit them without code changes.
        'confidence_gate_enabled' => (bool) env('ATLAS_LOOP_CONFIDENCE_GATE_ENABLED', false),
        'confidence_model' => [
            'threshold' => (float) env('ATLAS_LOOP_CONFIDENCE_THRESHOLD', 0.93),
            'weights' => [], // empty => the model's conservative defaults; the calibration loop overwrites this
        ],
        // NEXT-LEVER 2 — ESCALATION LADDER. When a round fails to certify, escalate to a stronger tier
        // (best_of_n -> repair_from_refutation -> decompose -> escalate_provider) instead of giving up —
        // bounded by certification (the quality bar) and this round budget, NOT a fixed N. The ladder is the
        // pure policy; the grind orchestrator walks it across the existing tiers.
        'escalation_max_rounds' => max(1, (int) env('ATLAS_LOOP_ESCALATION_MAX_ROUNDS', 6)),
        'escalation_thrash_threshold' => max(2, (int) env('ATLAS_LOOP_ESCALATION_THRASH_THRESHOLD', 3)),

        // ACDE Tier-1 #5: arms the autonomous conductor in the grinder — a no-winner best-of-N round
        // escalates STRUCTURALLY (repair->decompose->escalate) via AtlasLoopAutonomousConductor instead
        // of dead-ending, feeding the attempt-ledger forward + thrash-jumping. Default OFF: real provider
        // spend (up to escalation_max_rounds deeper re-runs); arm after measuring conversion + cost.
        'conductor_escalation_enabled' => (bool) env('ATLAS_LOOP_CONDUCTOR_ESCALATION_ENABLED', false),
        // NEXT-LEVER 1 — COMPLETENESS. A goal's checklist of acceptance criteria must be covered (every
        // required criterion satisfied + coverage >= floor) — proves the change did the WHOLE job, not just
        // enough to pass one test. RECORDED always; GATE only when completeness_gate_enabled — default OFF /
        // empty checklist => byte-identical. The criteria-from-goal resolver feeds the checklist + satisfaction.
        'completeness_gate_enabled' => (bool) env('ATLAS_LOOP_COMPLETENESS_GATE_ENABLED', false),
        'completeness_min_coverage' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_COMPLETENESS_MIN_COVERAGE', 1.0))),
        // NEXT-LEVER 3 — INDEPENDENT MULTI-JUDGE CONSENSUS. The certifier RECORDS a consensus assessment over
        // independent verdicts (lens-diverse provider judges when supplied; else the adversarial panel +
        // refuters as correctness judges). GATE only when judge_consensus_gate_enabled — default OFF until the
        // lens-judge panel (dedicated provider judges per lens) feeds diverse verdicts. quorum: policy
        // (unanimous|n_of_m), min_pass, required_lenses, min_distinct_providers (independence).
        'judge_consensus_gate_enabled' => (bool) env('ATLAS_LOOP_JUDGE_CONSENSUS_GATE_ENABLED', false),
        'judge_consensus' => [
            'quorum' => [
                'policy' => (string) env('ATLAS_LOOP_JUDGE_CONSENSUS_POLICY', 'unanimous'),
                'min_distinct_providers' => max(1, (int) env('ATLAS_LOOP_JUDGE_CONSENSUS_MIN_PROVIDERS', 2)),
                'required_lenses' => array_values(array_filter(array_map(
                    static fn (string $l): string => trim($l),
                    explode(',', (string) env('ATLAS_LOOP_JUDGE_CONSENSUS_REQUIRED_LENSES', 'correctness,completeness')),
                ), static fn (string $l): bool => $l !== '')),
            ],
        ],
        // LEVER 5 — SPEC AMPLIFICATION floor (feature lane). A feature's frozen acceptance test IS its spec;
        // a thin test under-specifies a complex feature (spec-gaming). The gate refuses a feature whose
        // acceptance asserts fewer than this many cases — forcing the spec to be amplified (boundary/error/
        // idempotency) before provider budget is spent. 0 => OFF (byte-identical). Fail-open if no test file.
        'spec_amplification' => [
            'min_assertions' => max(0, (int) env('ATLAS_LOOP_SPEC_MIN_ASSERTIONS', 0)),
            'min_methods' => max(0, (int) env('ATLAS_LOOP_SPEC_MIN_METHODS', 0)),
        ],
        // LEVER 3 — behavioral-equivalence STRENGTH floor. For a refactor, "the sibling test is green" is
        // necessary but weak; this requires the frozen suite to be strong enough to have CAUGHT a behaviour
        // change — a minimum mutation KILL RATIO (killed/sampled) over the exhaustively sampled decision
        // mutants. 0.0 => OFF (byte-identical). Raise toward the >93%-confidence bar once the loop clears it.
        'mutation_kill_ratio_floor' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_MUTATION_KILL_RATIO_FLOOR', 0.0))),
        // LEVER 5 — provider routing ("MiniMax when you should, codex as you should"). Cheap classes
        // go to the cheap tier (MiniMax-M3), load-bearing classes stay on the strong default. Default
        // OFF => byte-identical. Fail-safe: a cheap provider that is not configured falls back to the
        // default. Turn ON once the cheap provider key (minimax_m27) is wired in the operator's setup.
        'provider_routing' => [
            'enabled' => (bool) env('ATLAS_LOOP_PROVIDER_ROUTING_ENABLED', false),
            'cheap_provider' => (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_PROVIDER', 'minimax_m27'),
            'cheap_model' => (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_MODEL', 'MiniMax-M3'),
            'cheap_classes' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_LOOP_PROVIDER_ROUTING_CHEAP_CLASSES', 'characterization_test,edge_fix'))))),
        ],

        // OBRA REPAIR (eixo-3, the autonomy multiplier) — when ON, a node whose DELIVERY fails
        // certification is RETRIED with label-only failure feedback (bounded), instead of halting on
        // the first failure. Raises per-node success, which compounds for a many-node obra. Repair
        // changes only the COUNT of attempts, never the bar (every retry faces the same gate stack).
        // DEFAULT-OFF: with it off the delivery runs exactly once (byte-identical to today). Two caps
        // bound spend: per-node (3) and per-obra (8); a byte-identical re-edit stops early (no-progress).
        'obra_repair_enabled' => (bool) env('ATLAS_LOOP_OBRA_REPAIR_ENABLED', false),
        'obra_repair_max_attempts_per_node' => max(1, (int) env('ATLAS_LOOP_OBRA_REPAIR_MAX_ATTEMPTS_PER_NODE', 3)),
        'obra_repair_max_attempts_per_obra' => max(1, (int) env('ATLAS_LOOP_OBRA_REPAIR_MAX_ATTEMPTS_PER_OBRA', 8)),
        'multi_file_refactor_timeout_seconds' => max(60, (int) env('ATLAS_LOOP_MULTI_FILE_REFACTOR_TIMEOUT_SECONDS', 600)),

        // ITEM8 — PLANNING PHASE ahead of best-of-N. A qualifying task (>=2 allowed_files OR objective_kind
        // refactor_/feature_) runs IntentSpecCompiler (NL goal -> falsifiable acceptance) then
        // ObraDecompositionPlanner (DAG validated by PlanReadinessGate, create-class node at seq 0)
        // INSTEAD of the one-node-per-existing-file buildPlan. Default-OFF => byte-identical (buildPlan
        // runs exactly as today). The production provider seam is fail-open: until a hermes_cli spec-only
        // call is wired it returns no spec/plan and the adapter falls back to buildPlan (so even ON is safe).
        'planning_enabled' => (bool) env('ATLAS_LOOP_PLANNING_ENABLED', false),
        'planning_spec_max_attempts' => max(1, (int) env('ATLAS_LOOP_PLANNING_SPEC_MAX_ATTEMPTS', 3)),
        'planning_plan_max_attempts' => max(1, (int) env('ATLAS_LOOP_PLANNING_PLAN_MAX_ATTEMPTS', 3)),

        // ACDE Leap 2 — HUMAN-FROZEN DECOMPOSITION BOUNDARY-ORACLE. Imports the proven single-target
        // moat (a human-frozen bar the model cannot author) into the DECOMPOSITION layer. With the flag
        // ON AND a per-objective fixture frozen/obra-decompositions/<goal-hash>.json present, the readiness
        // gate adds a DETERMINISTIC SUPERSET check: the generated DAG's node target_areas + create-class
        // set must SUPERSET the human-named required boundaries, else REPLAN with the missing-seam reasons.
        // OFF, or no oracle for the goal => degrades to the structural-only gate (byte-identical to today).
        // The oracle dir is overridable so tests can point it at a temp dir; default ships EMPTY (no
        // false-reject — operators add boundary fixtures per objective). No LLM plan-judge: correlated
        // weak-model self-grading is the Goodhart this moat forbids — the bar is named by a human only.
        'decomposition_oracle_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_ORACLE_ENABLED', false),
        'decomposition_oracle_dir' => env('ATLAS_LOOP_DECOMPOSITION_ORACLE_DIR', base_path('frozen/obra-decompositions')),

        // ACDE Leap 3 — OBRA NET-DIFF FULL CERTIFICATION. The single-target anti-gaming stack
        // (behavioral-equivalence floor, overfit-constant-return probe, diff-earned, mutation-kill-ratio,
        // completeness, cross-file consumer contracts) runs ONLY inside certify() on a single dirty tree —
        // the obra path never calls it, so a multi-file obra is anti-gaming WEAKER than a one-file change.
        // With this flag ON, the ASSEMBLED obra net diff (base_head..branch, replayed into a base_head
        // worktree) is routed through the FULL certify() against the obra's HUMAN-FROZEN payload.acceptance
        // (never the model spec). Catches "independently-green steps that conflict once assembled" — a node
        // that silently breaks a sibling's frozen command turns the whole obra RED. OFF (default) => the obra
        // path is byte-identical to today (certifyAggregateDrop / structural lane only — never called here).
        'obra_full_cert_enabled' => (bool) env('ATLAS_LOOP_OBRA_FULL_CERT_ENABLED', false),
        // Sub-gate: force completeness_gate on the obra acceptance so certify()'s resolver derives one
        // criterion per command + per cross-node consumer contract and RE-RUNS each on the net diff. Inert
        // unless obra_full_cert_enabled is also ON; empty-derivable checklist => byte-identical (fail-open).
        'obra_completeness_gate_enabled' => (bool) env('ATLAS_LOOP_OBRA_COMPLETENESS_GATE_ENABLED', false),

        // ACDE Leap 5 — DECOMPOSITION OUTCOME LEDGER + shape-prior REPLAN band. The loop compounds
        // decomposition competence: a durable corpus records one row per EXECUTED obra (structural plan
        // fingerprint -> real terminal outcome from the Leaps 2-3 certifier envelope / post-merge canary),
        // and the readiness gate consults a Wilson lower-bound certified-rate per shape. corpus_enabled arms
        // the RECORDER (append-only telemetry; no gate). shape_prior_gate_enabled arms the ADVISORY band: a
        // shape whose certified-rate lower-bound is below target with >= min_samples appends
        // 'shape_historically_thrashes' => REPLAN (cheap). Both default OFF => recorder no-op + prior never
        // consulted => byte-identical. Cold/thin corpus (n<min_samples => UNKNOWN) never blocks a novel shape.
        'decomposition_corpus_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_CORPUS_ENABLED', false),
        'shape_prior_gate_enabled' => (bool) env('ATLAS_LOOP_SHAPE_PRIOR_GATE_ENABLED', false),
        'shape_prior_min_samples' => max(1, (int) env('ATLAS_LOOP_SHAPE_PRIOR_MIN_SAMPLES', 8)),
        'shape_prior_target_rate' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_SHAPE_PRIOR_TARGET_RATE', 0.5))),

        // ACDE Leap 6 (design-judgement ceiling) — HUMAN-FROZEN NODE-INTERFACE CONTRACT. The boundary-oracle
        // anchors WHICH files are separate nodes; this anchors the required ABSTRACTION inside them. With the
        // flag ON AND a per-objective fixture frozen/obra-interfaces/<goal-hash>.json present, the assembled
        // obra net diff is replayed and each contracted file's REAL AST surface (nikic/php-parser — decorrelated
        // from the provider LLM) must honour the frozen contract: required public methods present, required
        // implements/extends satisfied, forbidden imports absent (dependency-direction / anti-inversion). A
        // violation refuses the obra. OFF, or no contract for the goal => no interface check (byte-identical).
        // No LLM design-judge: the bar is human-authored + the check is a deterministic AST census (ungameable).
        'interface_contract_enabled' => (bool) env('ATLAS_LOOP_INTERFACE_CONTRACT_ENABLED', false),
        'interface_contract_dir' => env('ATLAS_LOOP_INTERFACE_CONTRACT_DIR', base_path('frozen/obra-interfaces')),
        // ACDE Leap 6 plan-time half — the readiness gate consults the SAME interface contract's seam-to-seam
        // edge rules (must_depend_on / forbidden_depend_on) and REPLANS a DAG with a missing or inverted edge,
        // feeding the planner its first machine design-steering gap. OFF / no contract => byte-identical. This
        // is STEERING (the model can declare a clean edge then write a contaminated file — caught only by the
        // as-built AST cert above); arm both for the full design-judgement moat.
        'node_interface_plan_gate_enabled' => (bool) env('ATLAS_LOOP_NODE_INTERFACE_PLAN_GATE_ENABLED', false),

        // ACDE Leap 7 (spec/index-completeness ceiling) — CHANGED-PUBLIC-SYMBOL COVERAGE CENSUS. Closes the
        // fail-open hole where a changed PUBLIC symbol with no frozen command + no wired consumer emits ZERO
        // criteria and certifies silently. With the flag ON, the assembled obra net diff is replayed and every
        // public method DECLARED in the diff's added lines must be NAMED (whole-word) in the coverage corpus —
        // the source of the test files the obra's frozen acceptance commands run; an uncovered new public
        // symbol refuses the obra (refuse-until-named, anchored on AST + the literal test corpus). OFF =>
        // byte-identical. HONEST: name-reference is necessary not sufficient (a thin test naming the symbol
        // passes — true sufficiency needs a mutation/coverage anchor); container-string/reflection sites are
        // RECORDED as an audit signal, never gated.
        'changed_symbol_census_enabled' => (bool) env('ATLAS_LOOP_CHANGED_SYMBOL_CENSUS_ENABLED', false),
        // ACDE lever #3 — the SAME census on the LIVE Path B cert (not just the planning-OFF obra adapter
        // above, which has zero attempt-#1 reach). When ON, AtlasLoopSemanticImplementationCertifier::certify()
        // refuses any new PUBLIC method in the diff's added lines that is NOT named in the frozen acceptance's
        // coverage corpus — the deterministic clamp on the weak engine's WRONG-BUT-GREEN-with-un-exercised-
        // surface gaming. Necessary-not-sufficient (a thin naming test passes) so it COMPOSES with the
        // mutation kill-ratio floor, never replaces it. OFF => byte-identical; a refute only appends a reason.
        'changed_symbol_census_path_b_enabled' => (bool) env('ATLAS_LOOP_CHANGED_SYMBOL_CENSUS_PATH_B_ENABLED', false),
        // ACDE lever #2b — run the BroaderRegressionGate's affected-module suites on ./vendor/bin/phpunit
        // instead of `artisan test` (the latter's autoloader-redeclare exit-255 hazard would spuriously RED a
        // whole-directory run). Default OFF => `artisan test` => byte-identical for the gate's existing (obra)
        // consumer + its tests; arm it together with broader_regression_gate_live.
        'broader_regression_gate_phpunit' => (bool) env('ATLAS_LOOP_BROADER_REGRESSION_GATE_PHPUNIT', false),
        // ACDE lever #7 — per-TARGET_PATH hopeless skip. The strategy bandit buckets by target TYPE and so
        // can never say "THIS file went N attempts with 0 certs — stop re-rolling it." When ON, the grinder
        // short-circuits a target with >= per_target_skip_min_attempts REAL attempts (provider-invoked,
        // non-trivial tokens) and ZERO certifications to a terminal honest refusal (completeTask success=false,
        // never releaseClaim) BEFORE best-of-N burns. Attacks the measured "targets already-clean files"
        // waste. A skip is itself a DQS refusal (=defect): it produces no fake success, it frees budget.
        // Default OFF => verdict always 'open' (no extra query) => byte-identical.
        'per_target_skip_enabled' => (bool) env('ATLAS_LOOP_PER_TARGET_SKIP_ENABLED', false),
        'per_target_skip_min_attempts' => max(1, (int) env('ATLAS_LOOP_PER_TARGET_SKIP_MIN_ATTEMPTS', 6)),
        // ACDE lever #6 — SEQUENCED single-method extract decompose. The weak engine cannot one-shot a god-
        // class; when ON, the framework-refactor synthesizer tags each in-place worst-method reduction as one
        // STEP of a bounded extract sequence (AtlasLoopExtractSequencePlanner). Each grind wave re-discovers
        // the still-complex file and pins its CURRENT worst method, so the class is decomposed worst-first
        // across waves — the composition of certified single-method reductions IS the big delivery, all
        // through the proven Path B cert (never the blocking obra-DAG). tractable_cyclomatic is the decompose
        // target (the chain ends when the worst method drops below it); max_steps bounds the projected plan.
        // Default OFF => no sequence tag => byte-identical.
        'extract_sequence_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_SEQUENCE_ENABLED', false),
        'extract_sequence_tractable_cyclomatic' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_TRACTABLE_CYCLOMATIC', 10)),
        'extract_sequence_max_steps' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_MAX_STEPS', 6)),
        // ACDE R3 — budgeted cross-file extract SEQUENCE: when the extract-CLASS branch runs, spin out a CHAIN
        // of distinct Support / Support2 / Support3 classes across waves (pick the lowest step whose file does
        // not yet exist), decomposing a god-class into cohesive classes instead of colliding on one name. The
        // chain is bounded by max_steps; once exhausted it falls through to in-place reduction. Default OFF =>
        // step 1 => the historical single `<Target>Support.php` => byte-identical.
        'extract_class_sequence_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_CLASS_SEQUENCE_ENABLED', false),
        'extract_class_sequence_max_steps' => max(1, (int) env('ATLAS_LOOP_EXTRACT_CLASS_SEQUENCE_MAX_STEPS', 3)),
        // ACDE F2 — carry the feature COMPLETENESS CHECKLIST (one falsifiable criterion per verification atom)
        // on the compiled intent-verifier packet, so a delivery dossier reports per-criterion completeness
        // instead of one opaque green bit. Pure restatement of the atoms the verifier already enforces (no
        // self-grading); the verifier_hash is computed over atoms/acceptance/test_content, never the whole
        // packet, so the key never shifts cert. Default OFF => key absent => byte-identical.
        'feature_completeness_checklist_enabled' => (bool) env('ATLAS_LOOP_FEATURE_COMPLETENESS_CHECKLIST_ENABLED', false),
        // ACDE F3 — the per-delivery HMAC-signed dossier (feature-outcome ledger). recent() reads the merged
        // deliveries (atlas_loop_proposals) and emits signed dossiers carrying the D2 dimensions + F2 checklist;
        // no new table, no merge-path write. Substrate that compounds when origination (O2) reads it back.
        // Default OFF => empty => byte-identical. dossier_hmac_secret keys the tamper-evidence signature; the
        // built-in default keeps it verifiable within the box (override via env for cross-process trust).
        'delivery_dossier_enabled' => (bool) env('ATLAS_LOOP_DELIVERY_DOSSIER_ENABLED', false),
        'dossier_hmac_secret' => (string) env('ATLAS_LOOP_DOSSIER_HMAC_SECRET', ''),
        // ACDE F1 — carry the SEQUENCED-FEATURE plan on the compiled verifier packet: one huge feature's human-
        // frozen atoms partitioned into an ordered chain of <= max_step_atoms-sized steps, each step's frozen
        // sub-acceptance being exactly its atom subset (never a re-authored bar). Lets the loop build a big
        // feature incrementally. Default OFF => key absent => byte-identical.
        'feature_sequence_enabled' => (bool) env('ATLAS_LOOP_FEATURE_SEQUENCE_ENABLED', false),
        'feature_sequence_max_step_atoms' => max(1, (int) env('ATLAS_LOOP_FEATURE_SEQUENCE_MAX_STEP_ATOMS', 2)),
        // ACDE B4b — on a certified+merged delivery, record the PROVEN provider-safe contract (changed public
        // symbol NAMES + consumer-set via blast-radius + machine-resolved D2 dimensions + deterministic
        // confidence) into atlas_loop_delivery_contracts — the brain-feedback write-end the B3 read-back uses.
        // Best-effort post-commit, wrapped + self-gated: the merge NEVER depends on it. Default OFF => the
        // recorder is a no-op => the merge path is byte-identical (AtlasLoopAutoMergeServiceTest stays green).
        'delivery_brain_feedback_enabled' => (bool) env('ATLAS_LOOP_DELIVERY_BRAIN_FEEDBACK_ENABLED', false),
        // ACDE X2 — deterministic parse-gate at the provider edit-apply site. When armed, a full-file .php block
        // that does not parse (nikic, in-process) is REJECTED before it is written, so a weak engine's broken
        // rewrite never poisons the scenario workspace (the fatal-autoload → diff-0 → certifies-nothing trap).
        // Default OFF => the check is skipped => writes are byte-identical to today.
        'parse_gate_enabled' => (bool) env('ATLAS_LOOP_PARSE_GATE_ENABLED', false),
        // ACDE U2 — red-REASON discriminator. AtlasEvolutionTaskGenerator::isRed accepts ANY non-zero exit as a
        // real RED task, so a weak engine's structurally-broken test (does not parse / wrong require path) is
        // mistaken for genuine behavioral work. When armed, a generated RED must additionally be BEHAVIORAL
        // (deterministic: parses + exercises the target + fails without a load-time structural signature).
        // Default OFF => the gate never runs => task generation is byte-identical.
        'red_reason_gate_enabled' => (bool) env('ATLAS_LOOP_RED_REASON_GATE_ENABLED', false),
        // ACDE O1 — the in-lane ORIGINATION producer: author a PROPOSE-ONLY origination proposal (structure +
        // decomposition hint, NO frozen acceptance, EMPTY diff, NEVER executeAndProve). Safe by construction:
        // empty diff + no acceptance_contract => the drain reprove fails closed => the row is RETIRED on the
        // first pass (never merged, never clogs); forbidden self-targets are dropped before authoring. Default
        // OFF => produce() is inert => byte-identical (the producer is never constructed in the OFF path).
        'origination_producer_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_PRODUCER_ENABLED', false),
        // ACDE O2 (the 3rd MULTIPLIER) — record the operator ACCEPT/REJECT on O1 origination proposals
        // (atlas:loop:origination-review) and let the producer BACK OFF shapes the operator keeps rejecting.
        // The human accept/reject sharpens the next authored origination — the only ground truth for
        // origination quality. min_samples + target_rate are OPERATOR-FROZEN (anti-Goodhart: the loop never
        // self-tunes its own origination bar); a thin/novel shape never backs off. Default OFF => no consult =>
        // byte-identical. Feedback stays inside origination — never the shared readiness gate or merge door.
        'origination_outcome_enabled' => (bool) env('ATLAS_LOOP_ORIGINATION_OUTCOME_ENABLED', false),
        'origination_backoff_min_samples' => max(1, (int) env('ATLAS_LOOP_ORIGINATION_BACKOFF_MIN_SAMPLES', 3)),
        'origination_backoff_target_rate' => (float) env('ATLAS_LOOP_ORIGINATION_BACKOFF_TARGET_RATE', 0.5),
        // ACDE S2 (observe-only) — record a PROVIDER-SAFE trace of what the EarnedAutonomy gate WOULD decide
        // (decision + risk rank + earned tier + the safety booleans + changed paths), so the operator can
        // audit the door for a long while BEFORE any decision to actuate it. OBSERVE-ONLY by construction: it
        // has NO apply/merge/canonize actuator. The live actuator is OUT OF SCOPE + BLOCKED pending an operator
        // governance decision + registering the proposal-gate/selector seam as sacred. Default OFF => inert.
        'earned_autonomy_decision_trace' => (bool) env('ATLAS_LOOP_EARNED_AUTONOMY_DECISION_TRACE', false),
        // ACDE R2-read (the MULTIPLIER) — when ON, the framework synthesizer reads the decomposition corpus
        // (R2's in-lane writes) for THIS shape's fingerprint and, if it has historically THRASHED (>=
        // min_samples outcomes, certified-rate < target_rate), backs the chain off to a single worst-method
        // step this round — a smaller, more-certifiable obra. So delivery N's recorded outcome grounds
        // delivery N+1 (the curve bends). Needs decomposition_corpus_enabled (R2) armed to have history.
        // Default OFF, and a thin corpus (< min_samples) yields UNKNOWN => no back-off => byte-identical.
        'extract_sequence_prior_read_enabled' => (bool) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_READ_ENABLED', false),
        'extract_sequence_prior_min_samples' => max(1, (int) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_MIN_SAMPLES', 4)),
        'extract_sequence_prior_target_rate' => (float) env('ATLAS_LOOP_EXTRACT_SEQUENCE_PRIOR_TARGET_RATE', 0.5),
        // ACDE lever D1 — the per-DELIVERY capability-trend instrument: the loop's OWN clean-delivery-rate
        // (committed + canary-not-red, the D2 definition) over rolling time buckets + the Wilson LB + the
        // SLOPE (is capability(t) bending upward?). A SELF-trend, never engine-vs-engine (Rivals is dead). It
        // READS atlas_loop_proposals (merged_to_main + quality) — no new table, no merge-path write. The ONLY
        // instrument that answers "did the curve actually bend." Default OFF => empty/inert trend => byte-
        // identical. Surface: `php artisan atlas:loop:capability-trend`.
        'capability_trend_enabled' => (bool) env('ATLAS_LOOP_CAPABILITY_TREND_ENABLED', false),

        // ACDE Leap 8 (greenfield ceiling) — REUSABLE DECOMPOSITION ARCHETYPE library. Imports the single-
        // target moat into greenfield: a human freezes a small library of archetypes (frozen/obra-archetypes/
        // *.json) — each a deterministic token classifier + the structural invariants every obra of that shape
        // must honour (min nodes, a mandatory create-class file suffix, min distinct targets). A goal that
        // classifies into an archetype is held to its invariants for ANY novel objective in the family — no
        // per-goal fixture. OFF, or no archetype match => structural-only (byte-identical). Ships EMPTY (the
        // library grows one human-authored rule at a time — the honest greenfield edge).
        'decomposition_archetype_enabled' => (bool) env('ATLAS_LOOP_DECOMPOSITION_ARCHETYPE_ENABLED', false),
        'decomposition_archetype_dir' => env('ATLAS_LOOP_DECOMPOSITION_ARCHETYPE_DIR', base_path('frozen/obra-archetypes')),

        // L4-1: cooldown por target recente. Tasks/proposals recentes do mesmo path caem no
        // ranking para evitar farming do arquivo que acabou de render proposta.
        'target_cooldown_enabled' => (bool) env('ATLAS_LOOP_TARGET_COOLDOWN_ENABLED', true),
        'target_cooldown_hours' => max(1, (int) env('ATLAS_LOOP_TARGET_COOLDOWN_HOURS', 24)),

        // L4-4: autópsia diária do Loop. Observa perdas/rejeições dominantes no ledger e
        // abre intents de backlog dedupados quando um padrão cruza o limiar.
        'loss_observer' => [
            'enabled' => (bool) env('ATLAS_LOOP_LOSS_OBSERVER_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_LOSS_OBSERVER_SCHEDULE_TIME', '05:20'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_LOSS_OBSERVER_WINDOW_HOURS', 24)),
            'min_occurrences' => max(2, (int) env('ATLAS_LOOP_LOSS_OBSERVER_MIN_OCCURRENCES', 3)),
            'manifest_limit' => max(10, (int) env('ATLAS_LOOP_LOSS_OBSERVER_MANIFEST_LIMIT', 200)),
        ],

        // L4-2: auto-alimentador diário do manifesto de backlog. Destila sinais reais e
        // endereçáveis (loss observer, corpus de falhas, residuais de campanha, scorecard
        // ACOS fraco, achados de sweep) em intents dedupados para a discovery L3-2.
        'backlog_auto_feed' => [
            'enabled' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SCHEDULE_TIME', '05:25'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_WINDOW_HOURS', 24)),
            'min_signal_count' => max(2, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MIN_SIGNAL_COUNT', 2)),
            'max_items' => max(1, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MAX_ITEMS', 8)),
            'manifest_limit' => max(10, (int) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_MANIFEST_LIMIT', 200)),
            'include_scorecard_weak_receipts' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SCORECARD_WEAK_RECEIPTS', true),
            'include_sweep_findings' => (bool) env('ATLAS_LOOP_BACKLOG_AUTO_FEED_SWEEP_FINDINGS', true),
        ],

        // L4-7: fila explícita para propostas parqueadas pelo auto-merge (ex.: alvo de
        // segurança do próprio harness). Não é schedulada; é uma ação soberana do operador.
        'operator_review' => [
            'enabled' => (bool) env('ATLAS_LOOP_OPERATOR_REVIEW_ENABLED', true),
            'limit' => max(1, (int) env('ATLAS_LOOP_OPERATOR_REVIEW_LIMIT', 10)),
        ],

        // L3-12: meta-loop — o Loop pode tocar o PRÓPRIO harness (não-segurança) quando ON.
        // O AtlasLoopHarnessGuard mantém o conjunto PROIBIDO pétreo (frozen judge, gates,
        // never-merge) INTOCÁVEL independentemente desta flag. Default OFF (anti-runaway).
        'meta_harness_targets' => (bool) env('ATLAS_LOOP_META_HARNESS_TARGETS', false),

        // L6-1: the "loop-proposes-harness" producer. When ON (and meta_harness_targets ON),
        // the backlog intent source deterministically enqueues admissible NON-SAFETY harness
        // files as named meta-improvement intents, so the meta_harness A/B arm fills as the
        // loop runs. Passes through the AtlasLoopHarnessGuard chokepoint (forbidden set stays
        // pétreo). Low priority so it never starves the ordinary backlog. Default OFF.
        'meta_harness_self_improve' => [
            'enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_ENABLED', true),
            'priority' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_PRIORITY', 0.4))),
            'max_candidates' => max(1, min(50, (int) env('ATLAS_LOOP_META_HARNESS_SELF_IMPROVE_MAX_CANDIDATES', 6))),
        ],

        // L6-1: meta-harness A/B lift. Read-only measurement; strict completion
        // requires real meta-harness and ordinary cases plus positive certification lift.
        'meta_harness_ab_lift' => [
            'enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_SCHEDULE_TIME', '06:15'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_WINDOW_HOURS', 168)),
            'min_cases_per_arm' => max(1, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MIN_CASES_PER_ARM', 3)),
            'min_lift' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MIN_LIFT', 0.01))),
            'max_tasks' => max(10, (int) env('ATLAS_LOOP_META_HARNESS_AB_LIFT_MAX_TASKS', 1000)),
        ],

        // L6-2: judge self-calibration. Historical RED-canary fix-forward tasks
        // compile into frozen verifier packets. Tighten-only: no providers, no
        // merge policy writes, and forbidden self-targets are refused.
        'judge_self_calibration' => [
            'enabled' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_SCHEDULE_TIME', '06:20'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_WINDOW_HOURS', 168)),
            'max_cases' => max(1, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_MAX_CASES', 8)),
            'timeout_seconds' => max(30, (int) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_TIMEOUT_SECONDS', 300)),
            'write_packets' => (bool) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_WRITE_PACKETS', true),
            'manifest_path' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_MANIFEST_PATH', storage_path('app/atlas/evidence/judge-self-calibration.json')),
            'packet_dir' => (string) env('ATLAS_LOOP_JUDGE_SELF_CALIBRATION_PACKET_DIR', storage_path('app/atlas/evidence/judge-self-calibration-packets')),
        ],

        // L6-3: explorer strategy portfolio bandit. Strategic routing only:
        // ranks existing scenario hints by target type from resolved attempt
        // metrics, applies only with measured certification-per-token lift.
        'explorer_strategy_bandit' => [
            'enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_ENABLED', true),
            'apply_enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_APPLY_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_SCHEDULE_TIME', '06:25'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_WINDOW_HOURS', 168)),
            'min_attempts_per_target_type' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_ATTEMPTS_PER_TYPE', 4)),
            'min_token_samples_per_strategy' => max(1, (int) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_TOKEN_SAMPLES_PER_STRATEGY', 1)),
            'min_token_efficiency_delta_per_1k' => max(0.0, (float) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_MIN_TOKEN_DELTA_PER_1K', 0.01)),
            'ucb_exploration_weight' => max(0.0, min(2.0, (float) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_UCB_EXPLORATION_WEIGHT', 0.35))),
            'receipt_path' => (string) env('ATLAS_LOOP_EXPLORER_STRATEGY_BANDIT_RECEIPT_PATH', storage_path('app/atlas/evidence/explorer-strategy-bandit.json')),
        ],

        // L6-4: code-graph auto-architecture proposals. Proposal-only:
        // reads Code Intelligence, parks a reviewable structural refactor draft,
        // and relies on the existing admission gate to prevent apply.
        'auto_architecture_proposals' => [
            'enabled' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULE_ENABLED', true),
            'scheduled_create_proposal' => (bool) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULED_CREATE_PROPOSAL', true),
            'schedule_time' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_SCHEDULE_TIME', '06:30'),
            'candidate_limit' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_CANDIDATE_LIMIT', 5)),
            'min_file_count' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_MIN_FILE_COUNT', 20)),
            'min_symbol_count' => max(1, (int) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_MIN_SYMBOL_COUNT', 120)),
            'receipt_path' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_RECEIPT_PATH', storage_path('app/atlas/evidence/auto-architecture-proposal.json')),
            'proposal_index_path' => (string) env('ATLAS_LOOP_AUTO_ARCHITECTURE_PROPOSALS_INDEX_PATH', 'atlas/loop/auto-architecture/proposal-index.json'),
        ],

        // L6-5: property-based + mutation adequacy gate. When semantic
        // certification is active, passing tests must kill a temporary mutant;
        // otherwise the proposal is refuted as an empty/weak-test survivor.
        'mutation_adequacy_gate' => [
            'enabled' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_SCHEDULE_TIME', '06:35'),
            'max_mutants' => max(1, (int) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_MAX_MUTANTS', 1)),
            'timeout_seconds' => max(10, (int) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_TIMEOUT_SECONDS', 120)),
            // For REFACTOR contracts (complexity_proof + metric_kind=minimize) sample DECISION
            // mutators only — a behaviour-preserving refactor that RELOCATES an unasserted string
            // literal must not be falsely rejected (mutation_survived) on that cosmetic mutant.
            // Default OFF = byte-identical legacy first-mutation-wins (fail-closed); flip ON once a
            // soak confirms real refactor certs appear. Never weakens true rejection: a surviving
            // DECISION mutant still rejects, and no producible decision mutant still rejects.
            'refactor_decision_aware' => (bool) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_REFACTOR_DECISION_AWARE', false),
            // ACDE lever #4 — route the FEATURE/bugfix lane through the SAME exhaustive added-DECISION
            // survivor hunt the refactor lane uses (exact-index firstAddedLineMutation, never the strpos
            // firstMutation that can land on byte-identical OLD code and re-open the false-certify hole),
            // instead of "sample <=max_mutants, return on first survivor". Makes the existing 0.5 kill-ratio
            // floor a REAL ratio over the actual added-branch surface — the sufficiency partner to the
            // changed-symbol census (#3) necessity check. Default OFF => the legacy strpos path =>
            // byte-identical. feature_lane_max_decisions bounds the per-task cost (one acceptance re-run per
            // probed target); a surviving decision within the probed set still hard-rejects.
            'exhaustive_added_decisions_feature_lane' => (bool) env('ATLAS_LOOP_MUTATION_EXHAUSTIVE_FEATURE_LANE', false),
            'feature_lane_max_decisions' => max(1, (int) env('ATLAS_LOOP_MUTATION_FEATURE_LANE_MAX_DECISIONS', 12)),
            'receipt_path' => (string) env('ATLAS_LOOP_MUTATION_ADEQUACY_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/mutation-adequacy-gate.json')),
        ],

        // L6-6: cross-file consumer verification. When the code graph can tie a
        // changed symbol to a consumer contract, semantic certification replays
        // that contract and refutes local-GREEN proposals that break consumers.
        'cross_file_consumer_gate' => [
            'enabled' => (bool) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_SCHEDULE_TIME', '06:40'),
            'timeout_seconds' => max(10, (int) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_TIMEOUT_SECONDS', 120)),
            'receipt_path' => (string) env('ATLAS_LOOP_CROSS_FILE_CONSUMER_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/cross-file-consumer-gate.json')),
        ],

        // L6-7: long-horizon observed-behavior regression oracle. This extends
        // the self-improvement regression sentinel with behavior contracts that
        // came from real observations rather than test specs. Receipt-only and
        // read-model-only: it blocks promotion when called with drifting snapshots,
        // but never mutates code, policy, provider routing or merge state.
        'self_improvement_regression_oracle' => [
            'enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_SCHEDULE_TIME', '06:45'),
            'receipt_path' => (string) env('ATLAS_LOOP_SELF_IMPROVEMENT_REGRESSION_ORACLE_RECEIPT_PATH', storage_path('app/atlas/evidence/self-improvement-regression-oracle.json')),
        ],

        // L6-8: formal-light invariant gate over the sensitive kernel floor.
        // Receipt-only and tighten-only: verifies reproducible proof envelopes
        // for the Constitutional Kernel, governed never-merge DB door and
        // HarnessGuard forbidden targets; never calls providers or mutates code.
        'formal_invariant_gate' => [
            'enabled' => (bool) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_SCHEDULE_TIME', '06:50'),
            'receipt_path' => (string) env('ATLAS_LOOP_FORMAL_INVARIANT_GATE_RECEIPT_PATH', storage_path('app/atlas/evidence/formal-invariant-gate.json')),
        ],

        // 24h-autonomia: respawn automático do supervisor morto (heartbeat velho + processo
        // ausente ⇒ relança detached, resume). Motivado pela morte silenciosa de 12/06.
        'keepalive_enabled' => (bool) env('ATLAS_LOOP_KEEPALIVE_ENABLED', true),

        // L4-6: painel 24h no digest matinal. Read-only: agrega funil, merges,
        // impact receipts, canários, custo medido, eventos de keepalive e propostas
        // estacionadas para o operador. O keepalive também registra um JSONL curto
        // para esta leitura; falha de escrita nunca derruba o respawn.
        'morning_digest' => [
            'enabled' => (bool) env('ATLAS_LOOP_MORNING_DIGEST_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_MORNING_DIGEST_SCHEDULE_TIME', '05:35'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_MORNING_DIGEST_WINDOW_HOURS', 24)),
            'keepalive_event_log_enabled' => (bool) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_ENABLED', true),
            'keepalive_event_log_path' => (string) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_PATH', storage_path('app/atlas/loop/keepalive-events.jsonl')),
            'keepalive_event_log_max_lines' => max(100, (int) env('ATLAS_LOOP_KEEPALIVE_EVENT_LOG_MAX_LINES', 2000)),
        ],

        // L5-1: pauta semanal governada. Propõe prioridades a partir de
        // evidência resolvida (digest/delta/backlog/final-capture) e, no
        // schedule, grava somente um draft no backlog para aprovação humana.
        'weekly_agenda' => [
            'enabled' => (bool) env('ATLAS_LOOP_WEEKLY_AGENDA_ENABLED', true),
            'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULE_DAY', 1))),
            'schedule_time' => (string) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULE_TIME', '05:45'),
            'window_hours' => max(24, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_WINDOW_HOURS', 168)),
            'max_items' => max(1, (int) env('ATLAS_LOOP_WEEKLY_AGENDA_MAX_ITEMS', 5)),
            'scheduled_create_proposal' => (bool) env('ATLAS_LOOP_WEEKLY_AGENDA_SCHEDULED_CREATE_PROPOSAL', true),
        ],

        // L5-14: weekly human-readable report. It is a source artifact for the
        // weekly agenda, not an approval surface and not a provider runtime.
        'weekly_report' => [
            'enabled' => (bool) env('ATLAS_LOOP_WEEKLY_REPORT_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_ENABLED', true),
            'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_DAY', 1))),
            'schedule_time' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_SCHEDULE_TIME', '05:40'),
            'hours' => max(24, (int) env('ATLAS_LOOP_WEEKLY_REPORT_HOURS', 168)),
            'max_words' => max(120, min(600, (int) env('ATLAS_LOOP_WEEKLY_REPORT_MAX_WORDS', 420))),
            'report_path' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_PATH', storage_path('app/atlas/evidence/weekly-engineering-report.json')),
            'markdown_path' => (string) env('ATLAS_LOOP_WEEKLY_REPORT_MARKDOWN_PATH', storage_path('app/atlas/evidence/weekly-engineering-report.md')),
        ],

        // L5-2: Loop -> Obra bridge. Builds a Forge handoff packet for
        // multi-file intents, but does not run providers or create Obras.
        'obra_bridge' => [
            'enabled' => (bool) env('ATLAS_LOOP_OBRA_BRIDGE_ENABLED', true),
            'min_files' => max(2, (int) env('ATLAS_LOOP_OBRA_BRIDGE_MIN_FILES', 2)),
            // When ON, the 24h supervisor auto-invokes the bridge preflight for any
            // claimed task whose intent spans >= min_files distinct files, parking a
            // governed Forge handoff packet for operator review. NEVER auto-merges and
            // NEVER dispatches a provider — the bridge stays preflight-only. Default OFF;
            // fail-open (a bridge error is logged to the ledger and the grind proceeds).
            'auto_escalate' => (bool) env('ATLAS_LOOP_OBRA_BRIDGE_AUTO_ESCALATE', false),
        ],

        // L5-13: fortnightly adversarial sweep. It reuses the governed backlog
        // manifest: LOW findings are queued as Loop intents, HIGH findings are
        // parked for operator review. No providers, no direct code mutation.
        'perpetual_sweep' => [
            'enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_ENABLED', true),
            'schedule_day' => max(0, min(6, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_DAY', 6))),
            'schedule_time' => (string) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_TIME', '06:05'),
            'schedule_week_parity' => max(0, min(1, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_SCHEDULE_WEEK_PARITY', 0))),
            'low_auto_fix_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_LOW_AUTOFIX_ENABLED', true),
            'high_review_enabled' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_HIGH_REVIEW_ENABLED', true),
            'include_backlog_feed' => (bool) env('ATLAS_LOOP_PERPETUAL_SWEEP_INCLUDE_BACKLOG_FEED', true),
            'max_findings' => max(1, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_MAX_FINDINGS', 12)),
            'manifest_limit' => max(10, (int) env('ATLAS_LOOP_PERPETUAL_SWEEP_MANIFEST_LIMIT', 200)),
            'carryover_findings' => [
                [
                    'path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
                    'reason' => 'snippet_payload_missing',
                    'severity' => 'high',
                    'priority' => 0.96,
                    'objective' => 'Separar snippet_payload_missing de no_acceptance_contract na re-prova snippet para aposentadoria/autopsia honesta.',
                    'source' => 'carryover:l4_12',
                ],
                [
                    'path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalMaterializer.php',
                    'reason' => 'rename_diff_normalization_requires_refusal_or_safe_normalization',
                    'severity' => 'high',
                    'priority' => 0.95,
                    'objective' => 'Refutar rename diffs no materializer: recusar ou normalizar explicitamente sem mascarar troca de path.',
                    'source' => 'carryover:l4_12',
                ],
            ],
        ],

        // L5-5: TAXA² dial overlay. The existing campaign supervisor consumes
        // these effective dials at boot; this never starts a parallel runtime,
        // never calls a provider and never touches the governed merge door.
        'taxa2_dials' => [
            'enabled' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_ENABLED', false),
            'kernel_sanctioned' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_KERNEL_SANCTIONED', true),
            'schedule_enabled' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_LOOP_TAXA2_DIALS_SCHEDULE_TIME', '05:55'),
            'window_hours' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_WINDOW_HOURS', 24)),
            'receipt_on_command' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_RECEIPT_ON_COMMAND', true),
            'receipt_on_supervisor_boot' => (bool) env('ATLAS_LOOP_TAXA2_DIALS_RECEIPT_ON_SUPERVISOR_BOOT', true),
            'max_delta_per_run' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_DELTA_PER_RUN', 2)),
            'max_queue_low_watermark' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_QUEUE_LOW_WATERMARK', 12)),
            'max_refill_batch' => max(1, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_REFILL_BATCH', 24)),
            'min_certification_rate' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_CERTIFICATION_RATE', 0.65))),
            'min_certified_to_merged' => max(0.0, min(1.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_CERTIFIED_TO_MERGED', 0.5))),
            'max_canary_failures_24h' => max(0, (int) env('ATLAS_LOOP_TAXA2_DIALS_MAX_CANARY_FAILURES_24H', 0)),
            'min_impact_receipt_coverage_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_IMPACT_RECEIPT_COVERAGE_PCT', 95.0))),
            'min_cost_coverage_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_TAXA2_DIALS_MIN_COST_COVERAGE_PCT', 80.0))),
        ],

        // L5-7: daily cost governor. This is not a second runtime; the
        // existing campaign budget fields remain authoritative. When a running
        // campaign approaches its configured cost cap, the supervisor reduces
        // scenarios per task; once the cap is reached, budgetStopReason()
        // returns cost_cap and the campaign stops.
        'cost_governor' => [
            'enabled' => (bool) env('ATLAS_LOOP_COST_GOVERNOR_ENABLED', false),
            'throttle_at_pct' => max(0.0, min(100.0, (float) env('ATLAS_LOOP_COST_GOVERNOR_THROTTLE_AT_PCT', 80.0))),
            'min_scenarios_per_task' => max(1, (int) env('ATLAS_LOOP_COST_GOVERNOR_MIN_SCENARIOS_PER_TASK', 1)),
        ],

        // 24h+ sem intervenção: revive campanhas que pararam por STARVATION de fila (a única
        // parada permanente — completed com budget sobrando). O loop mergeia código → novos
        // alvos surgem → reviver throttled re-descobre trabalho. Sem isto o soak para sozinho
        // após esgotar os alvos atuais e nunca volta.
        'keepalive_revive_starved' => (bool) env('ATLAS_LOOP_KEEPALIVE_REVIVE_STARVED', true),
        'keepalive_starved_revive_minutes' => (int) env('ATLAS_LOOP_KEEPALIVE_STARVED_REVIVE_MINUTES', 20),
        // Frozen-kill backstop threshold: a `running` campaign whose process is ALIVE but whose
        // heartbeat is stale longer than this is genuinely STUCK (not just slow). It MUST exceed the
        // max single-grind time (task_timeout_seconds, default 1800s = 30min) — otherwise the
        // watchdog kills HEALTHY long refactor grinds mid-flight (the heartbeat is not beaten during
        // a serial in-process grind), causing thrash: re-claim → re-grind → re-kill, never completing
        // (observed live: heavy refactors of complex services never certified, only fast tasks did).
        // 40min = the 30min grind cap + buffer; a truly-hung supervisor is still reaped at 40min.
        'keepalive_frozen_kill_minutes' => max(20, (int) env('ATLAS_LOOP_KEEPALIVE_FROZEN_KILL_MINUTES', 40)),
        // Reap window: a `running` row with no live process whose heartbeat is older than this is
        // an ABANDONED campaign (not a recently-died soak) — the watchdog marks it completed
        // (stop_reason=reaped_orphan_no_process) instead of respawning it. The recency bound is
        // what lets the respawn filter safely include unbounded (max_seconds<=0) soaks without
        // resurrecting ancient test zombies. Default 24h.
        'keepalive_reap_after_minutes' => max(60, (int) env('ATLAS_LOOP_KEEPALIVE_REAP_AFTER_MINUTES', 1440)),
        // Out-of-process code-drift backstop grace. The in-process drift-restart (campaign.
        // restart_on_code_drift) only fires at the TOP of the supervisor loop, so it is starved
        // during a long grind and goes dark entirely if the boot-time git HEAD read returned null
        // (observed live 2026-06-15: a pipeline fix sat un-loaded for 3h). When the SAME
        // restart_on_code_drift flag is ON, the keepalive ALSO recycles an alive supervisor whose
        // PROCESS START predates the newest engine commit — immune to both inner-loop failure modes.
        // This grace avoids killing a just-respawned (already-fresh) supervisor; the decision is
        // self-clearing (a recycle moves the start past the commit). Gated by restart_on_code_drift
        // so one flag controls the operator's churn-vs-autonomy choice for both checks.
        'keepalive_code_drift_grace_seconds' => max(60, (int) env('ATLAS_LOOP_KEEPALIVE_CODE_DRIFT_GRACE_SECONDS', 120)),

        // O-2 slice (d): universal adversarial certification. When ON, the DISCOVERY
        // path (not just framework tasks) routes every proposal through the semantic
        // certifier + adversarial panel before certified_for_review — closing the
        // Goodhart hole where the default 24h path trusted only a self-written frozen
        // judge. DEFAULT OFF: turning it on changes the live loop judge's strictness,
        // so it is deliberate (destravada junto da política merge-livre em O-3). When
        // on, a proposal that errors the gate is dropped (fail-closed), never the task.
        'universal_certification' => (bool) env('ATLAS_LOOP_UNIVERSAL_CERTIFICATION', false),

        // Auto-characterization-test lane (default OFF). When ON, a `characterization_test` objective
        // (produced only by the coverage-gap feeder) routes to the mutant-killed verifier instead of
        // the refactor cert: a provider-written test is kept only if it PASSES on correct code AND
        // FAILS on the gate's surviving mutant. Raises refactor conversion by closing coverage gaps,
        // never by lowering the mutation bar. Inert while OFF — the normal grind path is unchanged.
        'characterization_test_lane_enabled' => (bool) env('ATLAS_LOOP_CHARACTERIZATION_TEST_LANE_ENABLED', false),

        // L6-11: predictive outcome bridge. When ON, every terminal loop grind records a
        // real prediction + observed outcome into the predictive-failure calibration
        // surface (predictive_failure_insertions), so atlas:predict metrics compute
        // brier_score / avg_calibration_error on LIVE loop data instead of staying null.
        // DEFAULT OFF: this is loop telemetry feeding the cognitive calibration surface,
        // so turning it on is the operator's deliberate decision. The bridge is fail-open
        // (never crashes a grind) and writes telemetry only — no code, proposal, or merge.
        'predictive_outcome_bridge' => [
            'enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_ENABLED', false),
            'domain' => (string) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_DOMAIN', 'programming'),

            // L6-11 follow-up: daily recompute of the predictive_failure_calibration_metrics
            // read-model. Today those metrics (brier_score / avg_calibration_error) only
            // recompute on demand — when `atlas:predict metrics` runs or the L6-11 correlation
            // gate evaluates in live mode. Once the bridge above is feeding real loop grind
            // predictions+outcomes, this keeps the calibration table fresh for dashboards/digests
            // without waiting for a gate run. It recomputes the SAME domain the bridge writes.
            // DEFAULT OFF and gated on the bridge being ON too: with no bridge data there is
            // nothing to summarize, so turning it on is the operator's deliberate decision.
            'metrics_recompute' => [
                'enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_ENABLED', false),
                'schedule_enabled' => (bool) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_SCHEDULE_ENABLED', true),
                'schedule_time' => (string) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_SCHEDULE_TIME', '07:45'),
                'window_days' => max(1, (int) env('ATLAS_LOOP_PREDICTIVE_OUTCOME_BRIDGE_METRICS_RECOMPUTE_WINDOW_DAYS', 60)),
            ],
        ],

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
            // INDEPENDÊNCIA 24h+: teto de tempo de UM grind. Antes o grind herdava o budget
            // inteiro (7 dias) → uma chamada de provider travada congelaria o supervisor por
            // dias (keepalive não pega processo vivo). Capa em 30min/task; o budget total
            // segue respeitado (usa o menor entre os dois).
            'task_timeout_seconds' => max(60, (int) env('ATLAS_LOOP_TASK_TIMEOUT_SECONDS', 1800)),
            // Campaign exclusive lock lease: a crashed supervisor's campaign is resumable after this.
            'lock_lease_seconds' => max(60, (int) env('ATLAS_LOOP_LOCK_LEASE_SECONDS', 3600)),
            // Supervisor heartbeat cadence (seconds).
            'heartbeat_seconds' => max(5, (int) env('ATLAS_LOOP_HEARTBEAT_SECONDS', 30)),
            // L4-5: supervisor bootstraps the git HEAD it started under and exits
            // cleanly when that HEAD changes, leaving the campaign running for
            // keepalive to respawn fresh code.
            'restart_on_code_drift' => (bool) env('ATLAS_LOOP_RESTART_ON_CODE_DRIFT', true),
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

        // ITEM6 — SCENARIO-LEVEL fan-out. The explorer's N attempts per task run as a bounded PARALLEL
        // wave (Process::start + non-blocking harvest) to hide the ~14min/attempt provider latency behind
        // width. Default-OFF => the serial for-loop is byte-identical. Distinct from `parallel` above
        // (that is TASK-level: one grind-task subprocess per task).
        'scenario_fanout' => [
            'enabled' => (bool) env('ATLAS_LOOP_SCENARIO_FANOUT_ENABLED', false),
            'width' => max(1, (int) env('ATLAS_LOOP_SCENARIO_FANOUT_WIDTH', 4)),
            'timeout_seconds' => max(30, (int) env('ATLAS_LOOP_SCENARIO_FANOUT_TIMEOUT_SECONDS', 600)),
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

            // ACDE S2 — the self-improvement APPLY actuator master switch. DEFAULT-OFF. Even ON it authorizes
            // NOTHING by itself: the actuator additionally requires KillAuthority::isAutonomyKilled()=false (the
            // operator kill-file + live heartbeat) AND a freshly re-derived STATUS_AUTO_APPLIED_EARNED verdict
            // (armed + earned-tier + drift-clean + red-team + not gate/invariant touch). With this OFF the
            // actuator returns flag_off before any gate or git call — byte-identical, working tree untouched.
            'self_improvement_apply_enabled' => (bool) env('ATLAS_RSI_SELF_IMPROVEMENT_APPLY', false),
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
        // AP-815 W-8/W-11/E-2/E-7/I-3 tunables (all carry inline defaults in their services).
        'retention_days' => (int) env('ATLAS_CODE_GRAPH_RETENTION_DAYS', 90),
        'first_index_budget_files' => (int) env('ATLAS_CODE_GRAPH_FIRST_INDEX_BUDGET_FILES', 5000),
        'index_batch_size' => (int) env('ATLAS_CODE_GRAPH_INDEX_BATCH_SIZE', 500),
        'skeleton_elide_chars' => (int) env('ATLAS_CODE_GRAPH_SKELETON_ELIDE_CHARS', 200),
        'blast_depth' => (int) env('ATLAS_CODE_GRAPH_BLAST_DEPTH', 1),
        // AP-815 I-4: auto-pull a graph context pack into the Dev/Forge/loop agent flow
        // via CodeGraphAutoContextProvider. DEFAULT OFF — when off, the provider returns a
        // disabled/empty pack and touches no assembler, so wiring it into any context seam
        // is a pure no-op until the operator flips this on.
        'auto_context' => (bool) env('ATLAS_CODE_GRAPH_AUTO_CONTEXT', false),
        // AP-815 K1: token budget for the auto-context pack injected into the provider
        // prompt at the shared Open Brain seam (and Forge/Loop). Tunable; defaults to 4000.
        'auto_context_budget' => (int) env('ATLAS_CODE_GRAPH_AUTO_CONTEXT_BUDGET', 4000),
        // AP-815 C5: resolve symbol->symbol edges in the python_ai_data runtime
        // instead of the in-process PHP CodeGraphSymbolResolver. DEFAULT OFF.
        // The PHP resolver stays the default because (a) it is the proven
        // 99.5%-precision path and (b) shipping ~100k symbols across the
        // PHP->python boundary carries IPC/serialization overhead that may negate
        // any compute win — so this is an OPT-IN to MEASURE, not a default win.
        // Only takes effect when real_edges is on AND a Decision Receipt is minted;
        // if the runtime blocks/fails the build FALLS BACK to the PHP resolver.
        'python_resolve' => (bool) env('ATLAS_CODE_GRAPH_PYTHON_RESOLVE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder Intelligence Fase 2 (AP-818) — workspace nasce inteligente
    |--------------------------------------------------------------------------
    |
    | Gatilho on-link: ao vincular uma pasta num Projeto do Atlas Code, o
    | assembly de inteligência (index Code Intelligence + symbol graph; por
    | filho quando guarda-chuva) roda ENFILEIRADO — nunca inline no HTTP nem
    | em fluxo de chat. v2_enabled libera o schema v2 do retrato
    | (intelligence_status + workspace_id + amostras reais do read-model);
    | OFF = payload v1 byte-compatível. Flags default OFF; o operador liga
    | após a fatia provada (DoD AP-818).
    |
    */

    'code_folder_intelligence' => [
        'v2_enabled' => (bool) env('ATLAS_FOLDER_INTEL_V2', false),
        'auto_assemble' => (bool) env('ATLAS_FOLDER_INTEL_AUTO_ASSEMBLE', false),
        // Janela em que um índice existente é considerado fresco o bastante
        // para o re-link virar no-op (W-9 aplicado na admissão do assembly).
        'fresh_minutes' => (int) env('ATLAS_FOLDER_INTEL_FRESH_MINUTES', 30),
        // F2.5 — context packs (AOBG/MCP) pedem contexto no escopo do
        // guarda-chuva ativo: grafo agregado + grafos próprios dos membros.
        // OFF = retrieval single-workspace byte-idêntico ao atlas:ctx provado.
        'umbrella_context' => (bool) env('ATLAS_FOLDER_INTEL_UMBRELLA_CONTEXT', false),
        // F2.4 — re-rank semântico final por embeddings REAIS (semantic_rag,
        // fastembed local, receipt guard anti-fake). Default OFF; promoção de
        // runtime exige review humano (runtime_promotion_policy.v1).
        'semantic_rerank' => (bool) env('ATLAS_FOLDER_INTEL_SEMANTIC_RERANK', false),
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
    /*
    |--------------------------------------------------------------------------
    | AURG — Unified Reality Graph fused store (Phase-2 / Salto 1 F1)
    |--------------------------------------------------------------------------
    |
    | The cross-layer brain promised by AtlasRealityGraphSnapshotBuilderService:
    | atlas_aurg_nodes/atlas_aurg_edges federate BOUNDED provider-safe projections
    | of the 5 real read-models (memory, code-intelligence modules, the 21 canonical
    | cross-domain domains, the evidence ledger, ASRE strategic reality) plus
    | deterministic cite-or-omit cross-layer links. Intra-layer detail stays in the
    | sources (anti-duplication: no parallel graph beside Code Intelligence — the
    | brain holds compact refs, never the symbols or payloads).
    |
    | Ingestion is LOCAL-ONLY (reads local read-models, writes local tables, no
    | provider crossing) and operator-invoked (atlas:aurg:ingest); provider exposure
    | of brain content is governed downstream by the per-node provider_safe /
    | sensitive flags, never by this block. Caps keep the brain compact (hundreds
    | to low thousands of nodes).
    |
    | Service: app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php
    */
    'aurg' => [
        'enabled' => (bool) env('ATLAS_AURG_ENABLED', true),
        // Most-recent rows pulled per memory table (entries; verbatims same cap).
        'memory_limit' => (int) env('ATLAS_AURG_MEMORY_LIMIT', 500),
        // Recent evidence-ledger refs kept in the brain (ids/hashes only).
        'evidence_limit' => (int) env('ATLAS_AURG_EVIDENCE_LIMIT', 200),
        // Bounded code projection: top modules per workspace (never symbols).
        'modules_per_workspace' => (int) env('ATLAS_AURG_MODULES_PER_WORKSPACE', 300),
        // ASRE entities/relationships pulled per sync (expired decay skipped).
        'strategic_limit' => (int) env('ATLAS_AURG_STRATEGIC_LIMIT', 500),
        // Hard bound on nodes loaded per (source_kind,kind) by the linkers.
        'max_nodes' => (int) env('ATLAS_AURG_MAX_NODES', 5000),
        // --- F2 query (atlas:aurg:query / atlas_aurg_query MCP) ---
        // BFS depth from the seeds (service-side HARD cap 3, never raised).
        'query_depth' => (int) env('ATLAS_AURG_QUERY_DEPTH', 2),
        // Bounded answer: max nodes / edges collected per query.
        'query_max_nodes' => (int) env('ATLAS_AURG_QUERY_MAX_NODES', 60),
        'query_max_edges' => (int) env('ATLAS_AURG_QUERY_MAX_EDGES', 120),
        // Hybrid seed cap (semantic memory vectors + per-term lexical).
        'query_seed_limit' => (int) env('ATLAS_AURG_QUERY_SEED_LIMIT', 8),
        // Above this node count, ranking is delegated to the Python graph_rank
        // runtime (networkx). Below it, insertion order ('unranked_below_threshold').
        'query_rank_threshold' => (int) env('ATLAS_AURG_QUERY_RANK_THRESHOLD', 12),
        // Kill-switch for the Python ranking call (fallback stays HONEST:
        // 'unranked_disabled', insertion order, never fabricated scores).
        'query_rank_enabled' => (bool) env('ATLAS_AURG_QUERY_RANK_ENABLED', true),
        // --- F4 compounding + temporal + status ---
        // Ingest-on-write: every AtlasMemoryRegistryService write best-effort
        // upserts its brain node + row-scoped linkers (fail-open, never blocks
        // the memory write). Memory is the live accruing source.
        'ingest_on_write' => (bool) env('ATLAS_AURG_INGEST_ON_WRITE', true),
        // Daily full sync (atlas:aurg:ingest --prune) registered in
        // routes/console.php; also appends the daily AURG-4D snapshot tick.
        'schedule_enabled' => (bool) env('ATLAS_AURG_SCHEDULE_ENABLED', true),
        'schedule_time' => (string) env('ATLAS_AURG_SCHEDULE_TIME', '05:50'),
        // Defensive bounds for the temporal snapshot read (the brain is
        // hundreds-to-low-thousands by design; overflow reports truncated=true).
        'snapshot_max_nodes' => (int) env('ATLAS_AURG_SNAPSHOT_MAX_NODES', 20000),
        'snapshot_max_edges' => (int) env('ATLAS_AURG_SNAPSHOT_MAX_EDGES', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | AOBG — Atlas Open Brain Gateway (N1.F1 unified context-pack front door)
    |--------------------------------------------------------------------------
    | The SINGLE provider-bound PUSH surface any external AI calls first. It
    | FUSES the three proven brains (code-graph + AURG reality graph + semantic
    | memory) into ONE budgeted pack — it builds no parallel context engine.
    | Read-only, local DB only, no provider spend. Char budgets (the pack is a
    | text brief): total + per-source sub-budgets. Each section degrades to an
    | honest empty independently — the pack is a curated top-K, never omniscience.
    */
    'aobg' => [
        // L3-6: rerank semântico da seção de memória do context pack via o engine local
        // real (embeddings sobre os itens recuperados). Default OFF; fail-open sem venv.
        'semantic_retrieval' => (bool) env('ATLAS_AOBG_SEMANTIC_RETRIEVAL', false),
        // Total char budget for the assembled pack (a text brief, ~6000 chars).
        'budget_chars' => (int) env('ATLAS_AOBG_BUDGET_CHARS', 6000),
        // Per-source sub-budgets (the code-graph sub-budget is converted to a
        // token budget at ~4 chars/token for CodeGraphContextRetriever).
        'code_budget_chars' => (int) env('ATLAS_AOBG_CODE_BUDGET_CHARS', 2500),
        'memory_budget_chars' => (int) env('ATLAS_AOBG_MEMORY_BUDGET_CHARS', 2000),

        // N1.F3 — multi-project AUTO-ONBOARDING gate. The gateway works in ANY
        // project (auto-scoped from `cwd`/`workspace`); when a project is NOT yet
        // indexed it reports needs_onboarding + OFFERS the index command. This flag
        // governs whether atlas:aobg:workspace onboard may actually RUN the heavy
        // index of an arbitrary repo. Default FALSE: a heavy index is an operator
        // decision, never implicit. The status read is always honest either way.
        'auto_onboard' => (bool) env('ATLAS_AOBG_AUTO_ONBOARD', false),

        // Workspace registry API auto-activation. When the Atlas Desktop project/folder
        // screen creates, edits or swaps a real workspace_path, Atlas immediately binds
        // that folder into AWIS, writes provider bootstraps and indexes CodeGraph when
        // needed. PHPUnit disables this by env so tests never mutate the source tree.
        'workspace_api_auto_activate' => (bool) env('ATLAS_AOBG_WORKSPACE_API_AUTO_ACTIVATE', true),

        // N1.F2 — governed WRITE-BACK caps. Untrusted external input is bounded
        // BEFORE it reaches the brain: an oversized payload is rejected honestly
        // (not silently truncated), so a runaway external session cannot flood the
        // brain. These are size floors only — provider-safety + never-auto-promote
        // are structural in AtlasOpenBrainWriteBackService, not config-tunable.
        'write_back' => [
            'max_request_chars' => (int) env('ATLAS_AOBG_WB_MAX_REQUEST_CHARS', 2000),
            'max_id_chars' => (int) env('ATLAS_AOBG_WB_MAX_ID_CHARS', 256),
            'max_summary_chars' => (int) env('ATLAS_AOBG_WB_MAX_SUMMARY_CHARS', 1000),
            'max_files' => (int) env('ATLAS_AOBG_WB_MAX_FILES', 50),
            'max_memory_refs' => (int) env('ATLAS_AOBG_WB_MAX_MEMORY_REFS', 25),
            'max_evidence_refs' => (int) env('ATLAS_AOBG_WB_MAX_EVIDENCE_REFS', 25),
            'max_state_keys' => (int) env('ATLAS_AOBG_WB_MAX_STATE_KEYS', 50),
        ],

        // N2.F1 — the ACTIVE brain: context that FOLLOWS the task (PostToolUse).
        // `atlas:aobg:file-context` returns what the brain KNOWS about a file the
        // engine just touched (decisions/memories/missions/AURG paths/code
        // neighbors). It runs on the operator's interactive hook path, so every
        // knob here is a PERF + COST floor: read-only local DB, fail-OPEN to
        // empty, hard query caps so a slow/broken hook can never stall a session.
        'file_context' => [
            // Total char budget for the assembled file-context brief (tighter than
            // the prompt pack — it is a per-file delta, not a whole-task pack).
            'budget_chars' => (int) env('ATLAS_AOBG_FC_BUDGET_CHARS', 3500),
            // Per-source caps — bound the rows each section presents (and the SQL
            // candidate window), so the queries stay fast on the hot path.
            'max_neighbors' => (int) env('ATLAS_AOBG_FC_MAX_NEIGHBORS', 12),
            'max_memory' => (int) env('ATLAS_AOBG_FC_MAX_MEMORY', 6),
            'max_paths' => (int) env('ATLAS_AOBG_FC_MAX_PATHS', 8),
            // Wall-clock soft budget (ms) the COMMAND self-reports against — the
            // hook itself enforces a hard `timeout` so a runaway can never block.
            'soft_budget_ms' => (int) env('ATLAS_AOBG_FC_SOFT_BUDGET_MS', 1500),
        ],

        // N2.F2 — the SENTINEL: the brain checks a proposed edit BEFORE it lands
        // (PreToolUse guardrails). `atlas:aobg:guard` evaluates a proposed
        // Edit/Write against the brain and returns {decision, reasons, evidence}.
        //
        // SAFETY-FIRST (non-negotiable): a guard that BLOCKS edits is high-stakes
        // — a false positive bricks the operator's session. So the DEFAULT
        // decision is `warn` (advisory: inject a heads-up, NEVER block). Hard
        // `block` is OPT-IN via `block_enabled` (default FALSE) AND limited to the
        // highest-confidence violations only (a sensitive/secret/cyber-class path
        // touch, or an EXACT registered-decision contradiction). Everything else,
        // and ANY error/timeout, FAILS OPEN to `allow` — the brain can never block
        // a session by accident. Read-only, local DB only, ZERO provider spend.
        'guard' => [
            // OPT-IN hard block. OFF ⇒ the guard can only ever return allow|warn;
            // a normal edit is NEVER blocked. Flipping it ON lets the guard return
            // `block` for the highest-confidence violations ONLY (see above).
            'block_enabled' => (bool) env('ATLAS_AOBG_GUARD_BLOCK_ENABLED', false),
            // Per-source caps — bound the rows each check inspects (and the SQL
            // candidate window) so the guard stays fast on the interactive path.
            'max_decisions' => (int) env('ATLAS_AOBG_GUARD_MAX_DECISIONS', 8),
            'max_duplicates' => (int) env('ATLAS_AOBG_GUARD_MAX_DUPLICATES', 8),
            'max_reasons' => (int) env('ATLAS_AOBG_GUARD_MAX_REASONS', 12),
            // Total char budget for the assembled warning the hook injects.
            'budget_chars' => (int) env('ATLAS_AOBG_GUARD_BUDGET_CHARS', 2500),
            // Wall-clock soft budget (ms) the COMMAND self-reports against — the
            // hook enforces a hard `timeout` so a runaway can never stall a session.
            'soft_budget_ms' => (int) env('ATLAS_AOBG_GUARD_SOFT_BUDGET_MS', 1500),
        ],

        // N2.F3 — STRUCTURAL capture: the session feeds the brain AUTOMATICALLY (not
        // voluntarily). `atlas:aobg:capture-session` distils a session transcript
        // (touched files + outcome + EXPLICIT, file-cited learnings) and feeds them
        // through the GOVERNED write-back — a provider-safe mission/evidence node
        // (never a merge) + `proposed` learnings awaiting human review (the capture
        // quality gate rejects noise; nothing auto-applies). It runs on a Stop/
        // SessionEnd hook, so every knob is a PERF + COST + ANTI-NOISE floor:
        // deterministic distill (NO provider/LLM call), local DB only, fail-OPEN —
        // capture can never block or stall session end. The hard guarantees
        // (provider-safety, never-merge, never-auto-promote) are STRUCTURAL in the
        // write-back, not config-tunable; these are only size/anti-noise bounds.
        'session_capture' => [
            // Max touched files recorded on the one mission node for a session (a
            // sprawling session is still ONE node; this just bounds the cited paths).
            'max_files' => (int) env('ATLAS_AOBG_SC_MAX_FILES', 50),
            // Max EXPLICIT learnings fed as `proposed` from a single session (anti-
            // flood: a runaway session can't mint unbounded review-queue items).
            'max_learnings' => (int) env('ATLAS_AOBG_SC_MAX_LEARNINGS', 10),
            // Per-learning summary char cap (oversized is trimmed, not rejected — the
            // citation is the load-bearing part; the gate judges substance).
            'max_learning_chars' => (int) env('ATLAS_AOBG_SC_MAX_LEARNING_CHARS', 600),
            // Transcript read bounds — a pathological transcript can never blow the
            // hot-path budget. Read at most this many bytes / lines, then distil.
            'max_transcript_bytes' => (int) env('ATLAS_AOBG_SC_MAX_TRANSCRIPT_BYTES', 8000000),
            'max_transcript_lines' => (int) env('ATLAS_AOBG_SC_MAX_TRANSCRIPT_LINES', 20000),
        ],

        // N2.F4 — the BLACKBOARD: multiple engines coordinate THROUGH the brain. A
        // small, expiring table of CLAIMS of work ("codex is editing fileX") that
        // Claude Code AND Codex (both speak MCP) read + write so they step around
        // each other instead of stomping the same target. The F2 guard reads it to
        // surface a cross-engine claim as an ADVISORY warn (never a block). Local DB
        // only, ZERO provider spend. Knobs are size/TTL floors only — coordination is
        // metadata, never a gate.
        'blackboard' => [
            // Default claim TTL (seconds). A claim auto-expires after this so a
            // crashed engine's claim never blocks coordination forever. Per-claim ttl
            // overrides it; this is the fallback + the upper clamp.
            'default_ttl_seconds' => (int) env('ATLAS_AOBG_BB_DEFAULT_TTL_SECONDS', 3600),
            // Hard ceiling on any requested ttl (anti-runaway: an engine cannot claim
            // a target for a week).
            'max_ttl_seconds' => (int) env('ATLAS_AOBG_BB_MAX_TTL_SECONDS', 86400),
            // Max active claims listed for a workspace (the status read window).
            'max_active' => (int) env('ATLAS_AOBG_BB_MAX_ACTIVE', 200),
            // Char cap on the target label (a path/task-ref is short; this bounds it).
            'max_target_chars' => (int) env('ATLAS_AOBG_BB_MAX_TARGET_CHARS', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mission delivery — the CLOSED mission loop (S2.F1, "brain feeds hands")
    |--------------------------------------------------------------------------
    |
    | MissionDeliveryOrchestrator fuses cognition with execution: BEFORE the
    | provider generates code it queries the AURG brain (ALWAYS provider_bound
    | so sensitive/secret never rides a provider prompt), threads the top paths
    | into the code-gen prompt as provenance-cited reference context, and AFTER
    | a delivered branch records the outcome back INTO the brain (mission node +
    | generated edge to the branch + evidence node + references to touched
    | modules/memories) so the NEXT mission's brain query sees the prior one.
    |
    | Default-OFF: flipping brain_context_enabled to true changes the live
    | prompt sent to the provider, so it stays gated until the F5 .env flip.
    | Both the brain query and the outcome recording are FAIL-OPEN — a brain
    | outage degrades the loop but never breaks a delivery.
    |
    */
    'mission' => [
        // Gate for threading AURG context into the code-gen prompt (F5 flip).
        // OFF ⇒ the prompt is byte-identical to the no-brain path.
        'brain_context_enabled' => (bool) env('ATLAS_MISSION_BRAIN_CONTEXT_ENABLED', false),
        // How many top AURG paths are formatted into the brain_context string.
        'brain_context_paths' => (int) env('ATLAS_MISSION_BRAIN_CONTEXT_PATHS', 6),
        // Record the delivered branch outcome back into the brain (default ON:
        // it only writes mission-source nodes/edges, never touches main, and is
        // fail-open). Set false to disable the write-back entirely.
        'record_outcome_enabled' => (bool) env('ATLAS_MISSION_RECORD_OUTCOME_ENABLED', true),
        // S2.F4 — place each delivered mission's accrual into the AURG 4D temporal
        // chain (a real graph-state tick after the outcome is recorded). Default ON:
        // it only appends to the append-only tick log, never touches main, and is
        // fail-open (a tick failure never breaks the delivery). Off ⇒ no tick.
        'record_temporal_enabled' => (bool) env('ATLAS_MISSION_RECORD_TEMPORAL_ENABLED', true),
        // G3 — HTTP/Job wire: POST /ai/missions enfileira uma mission delivery
        // (AtlasMissionService::run em background via queue database-long) com
        // registro durável p/ polling em GET /ai/missions/{id}. Default OFF —
        // uma mission gasta provider; ligar = decisão operacional (.env).
        'http_delivery_enabled' => (bool) env('ATLAS_MISSION_HTTP_DELIVERY_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Obra — AOBG N3.F1 — the DECOMPOSITION spine (intent → plan-DAG → steps)
    |--------------------------------------------------------------------------
    |
    | THE INVERSION: the operator declares an INTENT in natural language and Atlas
    | decomposes it into an OBRA (a multi-step plan-DAG) it can later execute governed
    | onto ONE ready-to-merge branch. This config bounds the PLANNING half (cost-free
    | by default — the deterministic decomposer spends nothing).
    |
    | decompose_provider: the provider key the REAL decomposer asks to break an intent
    | into steps. EMPTY (default) ⇒ the deterministic, ZERO-spend decomposer is used.
    | Set a key (e.g. 'codex') only when the operator wants a provider-quality plan;
    | even then the spine degrades honestly to deterministic on any provider fault.
    |
    | max_nodes: hard cap on the plan size. An over-cap decomposition is REFUSED (never
    | silently truncated into an invalid partial DAG).
    |
    */
    'obra' => [
        'enabled' => (bool) env('ATLAS_OBRA_ENABLED', true),
        // ACDE Leap 4 (Stage A) — de-orphan the antichain wave scheduler. With this ON, the executor
        // computes the obra's parallelizable structure (Kahn antichain levels + same-level write-scope
        // collisions) and surfaces it in the envelope (wave_schedule) so the operator can MEASURE how often
        // real obras even have parallelizable levels before arming any delivery fan-out. PURE machine DAG
        // analysis — zero behaviour change; the strict serial walk is untouched. OFF (default) => the
        // scheduler is never invoked and execute() is byte-identical (no wave_schedule key).
        'node_fanout_observe' => (bool) env('ATLAS_OBRA_NODE_FANOUT_OBSERVE', false),
        // Provider key for the REAL decomposer. Empty ⇒ deterministic (cost-free).
        'decompose_provider' => (string) env('ATLAS_OBRA_DECOMPOSE_PROVIDER', ''),
        // Hard cap on plan-DAG nodes (an over-cap decomposition is refused).
        'max_nodes' => (int) env('ATLAS_OBRA_MAX_NODES', 12),
        // AOBG N3.F3 — the INTEGRATED certification check: a whole-branch test/measure
        // run ON THE ASSEMBLED obra worktree (NOT per step) after all steps pass. The
        // obra is certified=true ONLY when this RAN and PASSED on the assembled branch
        // (a per-step pass does not imply the whole integrates). Empty ⇒ NO whole-branch
        // proof ⇒ every obra is delivered needs_review (never silently stamped green on
        // per-step passes alone). Override per-run with --integrated-check. Example:
        //   'php artisan test --filter=Obra'  (run against the assembled worktree).
        'integrated_check' => (string) env('ATLAS_OBRA_INTEGRATED_CHECK', ''),
        // L4-10 — the HMAC secret the executor signs its self-stamped runtime receipt
        // with (and the L4-10 proof verifies against). Empty ⇒ falls back to app.key
        // (always present in a booted app). The executor that runs and the proof that
        // certifies live in the SAME Atlas, so they share this secret — which is why an
        // out-of-band hand-edit of the receipt can never reproduce the signature.
        'receipt_secret' => (string) env('ATLAS_OBRA_RECEIPT_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-construction — the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP (S3.F1)
    |--------------------------------------------------------------------------
    |
    | "Atlas improves Atlas", governed: detect an improvement signal → route it
    | through the BRAIN-ANCHORED mission loop (AURG context for that signal in,
    | outcome back into the brain so the next cycle sees it) → an OUT-OF-PROCESS
    | RELEVANCE GATE rejects off-target generation (the fix for the 412-line-
    | garbage failure) → a branch the OPERATOR reviews + merges. The loop NEVER
    | merges, NEVER pushes, NEVER touches main.
    |
    | use_brain_context defaults ON — brain-anchoring is the whole point of S3.
    | The autonomous --watch mode is gated default-OFF in the command (kill-switch
    | + per-cycle bounds); this config bounds a single run's fan-out.
    |
    */
    'self_construction' => [
        // Thread the AURG brain context for the signal into the code-gen prompt
        // (the AIM half of the 412-fix). Default ON — the whole point of S3.
        'use_brain_context' => (bool) env('ATLAS_SELF_CONSTRUCTION_USE_BRAIN_CONTEXT', true),
        // Hard cap on signals acted on in a single run (a single run can never fan
        // out unbounded self-modifying work, even if --max is set higher).
        'max_signals' => (int) env('ATLAS_SELF_CONSTRUCTION_MAX_SIGNALS', 5),

        // S3.F2 — the RELEVANCE GATE floors (the load-bearing safety that stops the
        // 412-line-garbage failure). A delivery is relevant iff target_match >= the
        // target floor AND content_relevance >= the content floor.
        //
        // target floor: a path match (exact file scores 1.0, sibling-in-dir 0.5) is
        // on-target at/above 0.5 — i.e. the named file or a defensible neighbour was
        // touched. Anything off-directory is 0.0 and rejected (the 412-line case).
        'relevance_min_target' => (float) env('ATLAS_SELF_CONSTRUCTION_RELEVANCE_MIN_TARGET', 0.5),
        // content floor: how much of the signal's concern vocabulary the generated
        // content must cover (token-overlap fallback) / how close the embeddings must be
        // (semantic). Low but non-zero — a Kanban driver for a scheduling TODO scores ~0.
        'relevance_min_content' => (float) env('ATLAS_SELF_CONSTRUCTION_RELEVANCE_MIN_CONTENT', 0.15),
        // Use REAL embeddings (semantic_rag/openai) for content_relevance on pgsql. When
        // off, or off pgsql, the gate uses the HONEST deterministic token-overlap fallback
        // (and labels it as such — it never calls a token score "semantic").
        'relevance_semantic_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_RELEVANCE_SEMANTIC', true),

        // S3.F3 — the AUTONOMOUS --watch mode kill-switch, at config level. The
        // continuous self-modifying loop ("Atlas changes Atlas unattended") is the
        // HIGHEST-stakes capability, so it requires an EXPLICIT opt-in IN ADDITION to
        // the --watch CLI flag and the per-cycle kill-switch file. Default OFF: --watch
        // refuses to start unless the operator turns this on, so the autonomous loop can
        // NEVER run by accident. A single (non-watch) run is unaffected; the relevance
        // gate makes each cycle safe, but unattended REPETITION stays operator-gated.
        'autonomous_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_AUTONOMOUS_ENABLED', false),

        // S3.F4 — GOVERNANCE HARDENING (safe to leave running).
        //
        // PER-RUN BRANCH CAP: the maximum number of branches a SINGLE run may KEEP
        // (accepted + held-for-review). Once reached the run halts further delivery, so
        // an unattended run can never fan out unbounded self-modifying work even if
        // many signals are detected. Defaults to max_signals (clamped to [1, max_signals]
        // by the loop — it can never exceed the signal fan-out bound).
        'max_branches_per_run' => (int) env('ATLAS_SELF_CONSTRUCTION_MAX_BRANCHES_PER_RUN', 3),

        // ADVERSARIAL RE-CHECK: a gate-passed branch is INDEPENDENTLY re-verified
        // (default-refute) before it is surfaced as worthy — a branch that passes the
        // gate but fails the re-check is HELD as needs_review, never claimed as vetted.
        // Default ON: the out-of-process Goodhart guard is part of the safe floor. (The
        // collaborator is also nullable for the legacy F1-F3 constructions in tests.)
        'adversarial_recheck_enabled' => (bool) env('ATLAS_SELF_CONSTRUCTION_ADVERSARIAL_RECHECK', true),

        // EVIDENCE / RECEIPT LOG path — the append-only JSONL audit trail of EVERY cycle
        // decision (accepted / rejected / needs_review / blocked). No silent action. Null
        // => the storage default (storage/app/atlas-self-construct-receipts.jsonl).
        'receipt_log_path' => env('ATLAS_SELF_CONSTRUCTION_RECEIPT_LOG_PATH'),
    ],

    // Polymarket 5-minute BTC Up/Down SHADOW runtime — no orders, no keys, no money.
    // Records what the deterministic strategy WOULD do against live public data and
    // scores calibration (Brier/EV). Live execution is a separate operator-gated obra.
    'finance_poly_shadow' => [
        'symbol' => env('ATLAS_POLY_SHADOW_SYMBOL', 'BTCUSDT'),
        'virtual_bankroll' => (float) env('ATLAS_POLY_SHADOW_BANKROLL', 200.0),
        'min_edge' => (float) env('ATLAS_POLY_SHADOW_MIN_EDGE', 0.04),
        'entry_min_sec' => (int) env('ATLAS_POLY_SHADOW_ENTRY_MIN_SEC', 15),
        'entry_max_sec' => (int) env('ATLAS_POLY_SHADOW_ENTRY_MAX_SEC', 180),
        'risk_pct' => (float) env('ATLAS_POLY_SHADOW_RISK_PCT', 0.05),
        'daily_halt_pct' => (float) env('ATLAS_POLY_SHADOW_DAILY_HALT_PCT', 0.10),
        // Polymarket taker fee for these fast markets; fee/share = rate*min(p,1-p).
        // Conservative default 0.0 — set from live market metadata before any live phase.
        'taker_fee_rate' => (float) env('ATLAS_POLY_SHADOW_TAKER_FEE_RATE', 0.0),
        'ewma_lambda' => (float) env('ATLAS_POLY_SHADOW_EWMA_LAMBDA', 0.97),
        'jump_z' => (float) env('ATLAS_POLY_SHADOW_JUMP_Z', 5.0),
        'jump_hold_sec' => (float) env('ATLAS_POLY_SHADOW_JUMP_HOLD_SEC', 60.0),
        'jump_sigma_mult' => (float) env('ATLAS_POLY_SHADOW_JUMP_SIGMA_MULT', 2.0),
    ],

    // Sum-of-legs inconsistency scanner over Polymarket multi-outcome (negRisk)
    // events. SHADOW ONLY: detects and records deviations from the no-arbitrage
    // band; never places orders.
    'finance_poly_arb' => [
        // Gamma-cached sums within this distance of 1.0 get live CLOB verification.
        'pre_filter_margin' => (float) env('ATLAS_POLY_ARB_PRE_FILTER_MARGIN', 0.02),
        'fee_per_set' => (float) env('ATLAS_POLY_ARB_FEE_PER_SET', 0.0),
        'max_clob_verifications' => (int) env('ATLAS_POLY_ARB_MAX_CLOB_VERIFICATIONS', 18),
        // Phantom-liquidity guard: events trading less than this in 24h get their
        // opportunities flagged dead_book (stale quotes may never fill).
        'min_volume_24hr' => (float) env('ATLAS_POLY_ARB_MIN_VOLUME_24HR', 50.0),
        // Net-of-gas cost model (capture cost per basket). Conservative estimates —
        // replaced by the real numbers the first $5 live basket measures. Long side
        // = settle/redeem tx; short side adds the mint tx. Per-leg = future fee knob.
        'cost_long_fixed' => (float) env('ATLAS_POLY_ARB_COST_LONG_FIXED', 0.10),
        'cost_short_fixed' => (float) env('ATLAS_POLY_ARB_COST_SHORT_FIXED', 0.20),
        'cost_per_leg' => (float) env('ATLAS_POLY_ARB_COST_PER_LEG', 0.0),
        // Fill-confidence probe: a market that traded within this many minutes is
        // "fillable"; within 6x is "slow"; beyond is phantom-risk (book may be stale).
        'fill_active_minutes' => (float) env('ATLAS_POLY_ARB_FILL_ACTIVE_MIN', 60.0),
        'fill_check_max' => (int) env('ATLAS_POLY_ARB_FILL_CHECK_MAX', 40),
    ],

    // Implication-violation scanner over logically ordered Polymarket market
    // pairs (A implies B => P(A) <= P(B)). SHADOW ONLY: detects books crossing
    // the implication band (YES bid of A > YES ask of B); never places orders.
    'finance_poly_implication' => [
        // Gamma-cached gaps within this distance of 0 get live CLOB verification.
        'pre_filter_margin' => (float) env('ATLAS_POLY_IMPLICATION_PRE_FILTER_MARGIN', 0.02),
        'fee_per_share' => (float) env('ATLAS_POLY_IMPLICATION_FEE_PER_SHARE', 0.0),
        'max_clob_verifications' => (int) env('ATLAS_POLY_IMPLICATION_MAX_CLOB_VERIFICATIONS', 40),
        // Phantom-liquidity guard: pairs whose less-traded side moves less than
        // this in 24h get their opportunities flagged dead_book.
        'min_volume_24hr' => (float) env('ATLAS_POLY_IMPLICATION_MIN_VOLUME_24HR', 50.0),
    ],

    // Polymarket shadow/sim executor. Default mode runs the entire state machine
    // against REAL books while signing NOTHING. The dormant live seam remains
    // blocked by the canonical Finance no_live_execution policy unless that
    // domain-level policy is explicitly changed outside this feature. Every gate
    // here is enforced IN CODE, not by prompt. Keys live in ATLAS_POLY_* env on
    // the local machine only — never in this file, the repo, the ledger or logs.
    'finance_poly_exec' => [
        // Feature-local live flag; this cannot override the Finance domain live
        // trading block.
        'live_enabled' => (bool) env('ATLAS_POLY_EXEC_LIVE_ENABLED', false),

        // Structural caps (USD).
        'max_basket_usd' => (float) env('ATLAS_POLY_EXEC_MAX_BASKET_USD', 8.0),
        'daily_cap_usd' => (float) env('ATLAS_POLY_EXEC_DAILY_CAP_USD', 25.0),
        'max_concurrent_baskets' => (int) env('ATLAS_POLY_EXEC_MAX_CONCURRENT', 2),

        // Liquidity / quality floors.
        // Executable depth must be at least this multiple of the stake before entry.
        'min_depth_multiple' => (float) env('ATLAS_POLY_EXEC_MIN_DEPTH_MULTIPLE', 3.0),
        // The opportunity must have persisted at least this long (lifecycle age) —
        // a flicker that vanishes in seconds is not executable.
        'min_persistence_seconds' => (int) env('ATLAS_POLY_EXEC_MIN_PERSISTENCE_SECONDS', 600),
        // Net edge per $1 set AFTER fee + amortized gas must clear this floor.
        'min_net_edge_per_set' => (float) env('ATLAS_POLY_EXEC_MIN_NET_EDGE_PER_SET', 0.01),
        // Prefer markets that resolve soon (v1 carries the long to resolution).
        'max_resolution_hours' => (float) env('ATLAS_POLY_EXEC_MAX_RESOLUTION_HOURS', 72.0),

        // Per-leg price tolerance: a limit buy may pay up to target*(1+bps/1e4); a
        // short sell may accept down to bid*(1-bps/1e4) (protective sell floor).
        'slippage_bps' => (int) env('ATLAS_POLY_EXEC_SLIPPAGE_BPS', 100),

        // Cost model (USD). CLOB limit orders are matched off-chain and gasless, so
        // the long-buy path itself costs ~0 gas; redeem-at-resolution is one cheap
        // Polygon tx. Kept configurable and folded into the net-edge floor.
        'taker_fee_rate' => (float) env('ATLAS_POLY_EXEC_TAKER_FEE_RATE', 0.0),
        'est_gas_usd_per_basket' => (float) env('ATLAS_POLY_EXEC_EST_GAS_USD', 0.0),

        // --- Short side (the motor: mint a full set on-chain for $1, sell legs > $1) ---
        // Short execution is allowed in sim regardless; live short additionally needs
        // the on-chain split capability wired AND proven by a minimal real mint.
        'short_enabled' => (bool) env('ATLAS_POLY_EXEC_SHORT_ENABLED', true),
        // Conservative per-set on-chain costs (Polygon gas), amortized into net edge.
        // To be REPLACED by the numbers the first real $5 mint measures.
        'est_mint_gas_usd' => (float) env('ATLAS_POLY_EXEC_EST_MINT_GAS_USD', 0.05),
        'est_merge_gas_usd' => (float) env('ATLAS_POLY_EXEC_EST_MERGE_GAS_USD', 0.05),
        // If a short basket mints but sells NOTHING, default to HOLDING the full set
        // (a risk-free $1-at-resolution freeroll) rather than merging back (extra gas).
        'short_merge_on_no_sell' => (bool) env('ATLAS_POLY_EXEC_SHORT_MERGE_ON_NO_SELL', false),
        // The short banks immediately on the sell, so it does NOT need a fast resolution
        // (unlike the long, which carries to resolution). A generous ceiling lets the
        // motor reach the slow weather/election/sports markets where the arb lives; it
        // only bounds the rare post-mint no-sell case that locks collateral.
        'short_max_resolution_hours' => (float) env('ATLAS_POLY_EXEC_SHORT_MAX_RESOLUTION_HOURS', 720.0),

        // --- Long realize policy ---
        // hold  = carry the bought set to resolution (proven v1 default).
        // merge = redeem the held set back to $1 on-chain immediately (needs the same
        //         on-chain capability + a minimal-merge proof before it's trusted).
        'long_realize_method' => env('ATLAS_POLY_EXEC_LONG_REALIZE', 'hold'),

        // Python binary for the live signer / on-chain runtime. Default 'python3'
        // (PATH); point at the poly_exec venv once live deps are installed.
        'python_bin' => env('ATLAS_POLY_PYTHON_BIN', 'python3'),

        // File kill-switch: if this path exists, nothing executes and any in-flight
        // basket aborts + unwinds. `touch` it to halt instantly without a deploy.
        'kill_switch_path' => env('ATLAS_POLY_EXEC_KILL_SWITCH_PATH', storage_path('app/atlas-poly-exec.kill')),

        // Live order backend (the ONLY path that can sign). Default points at the
        // governed Python runtime that wraps the canonical Polymarket CLOB SDK;
        // PHP never hand-rolls EIP-712 signing for real money.
        'live' => [
            // proxy | eoa | auto — proxy = Polymarket email/magic wallet (signature
            // type 1/2, funder = proxy address); eoa = own key (signature type 0).
            'account_kind' => env('ATLAS_POLY_ACCOUNT_KIND', 'auto'),
            // Read at runtime from env only; presence is detected, values never logged.
            'private_key_env' => 'ATLAS_POLY_PRIVATE_KEY',
            'funder_address_env' => 'ATLAS_POLY_FUNDER_ADDRESS',
            'api_key_env' => 'ATLAS_POLY_CLOB_API_KEY',
            'api_secret_env' => 'ATLAS_POLY_CLOB_API_SECRET',
            'api_passphrase_env' => 'ATLAS_POLY_CLOB_API_PASSPHRASE',
            'runtime_root' => 'runtimes/python/poly_exec',

            // On-chain (CTF split/merge) — the SHORT motor + long early-merge. These
            // are real Polygon transactions, so they cost gas and CANNOT be signed by
            // the CLOB SDK. The cleanest path is an EOA holding USDC.e; a proxy/magic
            // wallet routes funds through a proxy contract and stays fail-closed until
            // a relay path is wired. Addresses are Polygon mainnet (chain 137).
            'polygon_rpc_url_env' => 'ATLAS_POLY_POLYGON_RPC_URL',
            // Polymarket NegRisk multi-outcome events split/merge via the NegRiskAdapter;
            // vanilla single-condition markets via the ConditionalTokens framework.
            'neg_risk_adapter' => env('ATLAS_POLY_NEG_RISK_ADAPTER', '0xd91E80cF2E7be2e162c6513ceD06f1dD0dA35296'),
            'conditional_tokens' => env('ATLAS_POLY_CONDITIONAL_TOKENS', '0x4D97DCd97eC945f40cF65F87097ACe5EA0476045'),
            'usdc' => env('ATLAS_POLY_USDC', '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174'),
        ],
    ],

    // Executor LIVE de Binance spot (Fase B) — o ÚNICO caminho que envia ordem real.
    // Fail-closed em camadas: ordem real exige live_enabled=true E ATLAS_SPOT_EXEC_ARMED
    // E --confirm no comando E kill-switch ausente E caps respeitados. Default: tudo OFF.
    // Chaves lidas do env por NOME (nunca logadas); a chave Binance NÃO tem permissão
    // de saque e está restrita por IP — blast radius = saldo spot, nada mais.
    'finance_spot_exec' => [
        'live_enabled' => (bool) env('ATLAS_SPOT_EXEC_LIVE_ENABLED', false),
        'armed_env' => 'ATLAS_SPOT_EXEC_ARMED',          // precisa ser literalmente "true" no env
        'api_key_env' => 'ATLAS_BINANCE_API_KEY',
        'api_secret_env' => 'ATLAS_BINANCE_API_SECRET',
        'allowed_symbols' => ['BTCUSDT', 'ETHUSDT'],     // foco do operador: só BTC/ETH
        'max_order_usd' => (float) env('ATLAS_SPOT_EXEC_MAX_ORDER_USD', 10.0),
        'daily_cap_usd' => (float) env('ATLAS_SPOT_EXEC_DAILY_CAP_USD', 20.0),
        'kill_switch_path' => env('ATLAS_SPOT_EXEC_KILL_SWITCH_PATH', storage_path('atlas/finance/spot-exec/STOP')),
        'ledger_dir' => storage_path('atlas/finance/spot-exec'),
        'recv_window_ms' => 5000,
    ],

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
