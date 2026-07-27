<?php

use App\Services\Ai\AutonomousEvolution\AtlasLoopAdversarialVerifierPool;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLearningAppendService;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopSimulableTwinOrchestrator;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\Reality\AtlasAurgPprShadowDualReadLedger;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierHarvestGovernanceRunner;

return [
    'version' => env('ATLAS_VERSION'),
    'token' => env('ATLAS_TOKEN'),

    // PART 2 — the operator-facing task-serving queue. A DEDICATED disk isolates it from the certification
    // probe pollution in the shared Agent Control Plane queue. Default 'local' keeps the legacy shared queue.
    'task_serving' => [
        'queue_disk' => env('ATLAS_TASK_SERVING_QUEUE_DISK', 'local'),
        // RECLAIM-AFTER-GIVE-BACK cooldown (seconds): how long a task is withheld from the SAME worker that
        // gave it back before that worker may retry it. Bounded anti-loop, NOT a permanent lockout — a
        // permanent per-worker skip deadlocks the version-ladder for a single worker (every task it gave back,
        // and everything depending on it, becomes forever unservable). 0 disables (immediate reclaim).
        'give_back_reclaim_cooldown_seconds' => (int) env('ATLAS_TASK_SERVING_GIVE_BACK_RECLAIM_COOLDOWN_SECONDS', 600),
    ],

    // MULTV-10 — enforce SEAM for verified autonomous landings. Default-OFF; this
    // slice NEVER flips. The ONLY flip belongs to ASI-10 via ELEV-26s (1 flip per
    // family per window; ROL-01 rollback trigger pre-declared before the flip).
    // The operator port (`atlas:land`, commitAuthority=operator) is EXEMPT.
    //   ATLAS_MULTV_AUTONOMOUS_LAND_VERIFICATION_ENABLED
    //     OFF (default) — legacy behavior, byte-identical.
    //     ON (ASI-10 flip only)   — autonomous land without a sealed MULTV-01
    //       verification receipt is refused with `verification_receipt_missing`;
    //       an unsealed/tampered receipt is refused with `verification_receipt_seal_invalid`;
    //       a receipt whose declared tier is below the derived risk tier is refused
    //       with `verification_receipt_tier_below_risk`.
    //   ATLAS_MULTV_AUTONOMOUS_LAND_VERIFICATION_REQUIRED_TIER — T1|T2|T3 (default T1).
    //     Interim override until MULTV-02 lands the risk-tiered cascade that derives
    //     the required tier from the change context. Keep T1 while the cascade ships.
    'multv' => [
        'autonomous_land_verification_enabled' => (bool) env('ATLAS_MULTV_AUTONOMOUS_LAND_VERIFICATION_ENABLED', false),
        'autonomous_land_verification_required_tier' => env('ATLAS_MULTV_AUTONOMOUS_LAND_VERIFICATION_REQUIRED_TIER', 'T1'),
    ],

    // ESP-06 — OutcomeEnvelope adapters (Dev procedural, AEMOR, Compounding).
    // Default-OFF: native organ payloads stay byte-identical; when ON, each organ
    // may add an additive `outcome_envelope` projection via OutcomeEnvelopeBridge.
    // Anti-unification fence: adapters map fields; organs are never fused/renamed.
    'esp_06' => [
        'outcome_envelope_adapters_enabled' => (bool) env('ATLAS_ESP_06_OUTCOME_ENVELOPE_ADAPTERS_ENABLED', false),
    ],

    // Provider routing defaults (Checkpoint-B prep). The EXECUTION runtime is HERMES-NATIVE — a runtime sentinel,
    // NEVER a pinned model name (MiniMax is merely the model Hermes happens to use today, swappable). The
    // BRAIN/frontier-design default is Codex (the frontier connectable via a subscription account). Both are
    // env-swappable; NO model is hardcoded in code. See memory loop-checkpoint-A-vs-B-honest-split / loop-hermes-native-no-model-pin.
    'provider_defaults' => [
        'execution_runtime' => env('ATLAS_EXECUTION_RUNTIME', 'hermes_cli_default'),
        'brain_default' => env('ATLAS_BRAIN_PROVIDER', 'codex_cli'),
    ],

    // EXTERNAL BRAIN — the 3 thin commands that seed evolution work into the serving queue. The master switch
    // is INDEPENDENT of the loop/serving switches (author≠judge: the brain writes only to docs/ + the serving
    // queue, never app/, never commit/merge). Default OFF / fail-closed; flip via the operator-only switch.
    'brain' => [
        'master_enabled' => env('ATLAS_BRAIN_MASTER_ENABLED', false),
        'journal_root' => 'docs/autonomos-evolution-journal',
        'done_set_root' => storage_path('app/atlas/brain/done-set'),
        // CYCLE CAPSULE — replayable per-cycle external record (prompt/receipt, spec, decision, provider,
        // files, evidence, validation, metrics, failures, learning). One JSONL per scope. The substrate the
        // Internalization Pipeline replays to turn external cycles into internal capability candidates.
        'cycle_capsule_root' => storage_path('app/atlas/brain/cycle-capsule'),
        // Brain writer must fail cleanly in minutes, not inherit the loop's long provider-attempt budget.
        'writer_timeout_seconds' => max(5, (int) env('ATLAS_BRAIN_WRITER_TIMEOUT_SECONDS', 30)),
        // Modelo do writer do cérebro (originador). '' => provider self-select = comportamento atual.
        // Só tem efeito com ATLAS_BRAIN_PROVIDER=hermes_cli; use um slug servido pelo verboo
        // (glm-5.2, kimi-k2.7, kimi-k2.7-code, minimax-m3). NUNCA família Atlas-Decide (sonnet/…): cli_error.
        // Abstrato/env — trocar de motor amanhã = só mudar ATLAS_BRAIN_PROVIDER + ATLAS_BRAIN_WRITER_MODEL.
        'writer_model' => trim((string) env('ATLAS_BRAIN_WRITER_MODEL', '')),

        // The scope the brain evolves when none is named. The brain has ONE defined scope today: the whole
        // Autônomos block (brain + muscle). Add cortex/maestro/… here as DATA — no code change.
        'default_scope' => env('ATLAS_BRAIN_DEFAULT_SCOPE', 'autonomous'),
        'scopes' => [
            'autonomous' => [
                'label' => 'The Atlas Autônomos block — brain + muscle (Autônomos).',
                // Both halves of the autonomous block. AutonomousEvolution is harness-gated (the engine the
                // brain runs on); SelfConstruction is the muscle. meta_harness ON lets the brain evolve the
                // engine half too — the pétreo FORBIDDEN_SELF_TARGETS floor still protects judge/gates/switch.
                'roots' => [
                    'app/Services/Ai/AutonomousEvolution',
                    'app/Services/Ai/SelfConstruction',
                ],
                'docs_roots' => [
                    'docs/loop-canonical-definition.md',
                    'docs/atlas-brain-harness-build-spec.md',
                ],
                // RECURSIVE-TOTAL (operator decision): the brain MAY evolve its own engine. Honesty is held by
                // the pétreo floor — the brain can never touch the judge, the gates, the switches, its own
                // stop-probe/classifier/dedup/perception, nor config/atlas.php (all in FORBIDDEN_SELF_TARGETS).
                'meta_harness' => (bool) env('ATLAS_BRAIN_AUTONOMOUS_META_HARNESS', true),
            ],
            // Lane 24/7 AAEOS+ACOS — defatoração elite / confiabilidade. Mutation targets ONLY these
            // roots; Autônomos harness stays out of the objective. meta_harness OFF (fail-closed).
            'aaeos_acos' => [
                'label' => 'AAEOS+ACOS elite simplification/reliability lane (defatoração with proof).',
                'roots' => [
                    'app/Services/Ai/Aaeos',
                    'app/Services/Ai/AgenticEngineeringOs',
                    'app/Services/Ai/Cognition/AcosProgram',
                    'app/Services/Ai/Cognition',
                ],
                'docs_roots' => [
                    'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
                    'docs/engineering-knowledge-base/atlas-cognition-operating-system.md',
                    'docs/engineering-knowledge-base/atlas-acos-areas-map.md',
                    'docs/engineering-knowledge-base/atlas-aaeos-acos-elite-simplify-lane.md',
                ],
                'meta_harness' => false,
                'lane' => 'elite_simplify',
                'bias' => [
                    'collapse_duplicates',
                    'remove_dead_layer_with_proof',
                    'consolidate_facades',
                    'close_reliability_gaps',
                ],
            ],
        ],

        // AAEOS+ACOS simplify-cycle tick (scheduler). Default OFF — operator arms explicitly.
        'aaeos_acos_simplify_cycle' => [
            'schedule_enabled' => (bool) env('ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_SCHEDULE_ENABLED', false),
            'schedule_cadence_minutes' => max(5, (int) env('ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_CADENCE_MINUTES', 30)),
        ],

        // KEYSTONE FLAGS — external brain perception is default ON; opt out with env only when debugging.
        // ASI-08: reflection stream is default ON (local, cheap, reversible by env). It is
        // NOT a master flip; this is a write-local diagnostic so the derivative second stops
        // being zero. Governed by env only for tests/debug that need silence.
        'reflection_enabled' => (bool) env('ATLAS_BRAIN_REFLECTION_ENABLED', true),
        'reflection_root' => storage_path('app/atlas/brain/reflection-stream.ndjson'),
        // Where the pattern-learning ledger is appended (SERVER-SIDE at landing seam).
        'pattern_learning_ledger' => storage_path('atlas-loop/pattern-learning-ledger.jsonl'),
        'causal_selector_enabled' => (bool) env('ATLAS_BRAIN_CAUSAL_SELECTOR_ENABLED', false),
        // Structural-signal digest (comprehension-deepening): when ON, brain:next injects top-K orphan/clone/
        // doc-stated-gap signals into the served payload so the pasted brain can originate against multi-file
        // leverage instead of file-local micro-leverage. Set env false only for compatibility debugging.
        'scope_signal_digest_enabled' => (bool) env('ATLAS_BRAIN_SCOPE_SIGNAL_DIGEST_ENABLED', true),
        // MAXN-05 governed frontier fetcher. Live network is default-OFF; dry-run may still render the
        // provider-safe outbound plan for egress review without touching the network.
        'frontier_fetcher_enabled' => (bool) env('ATLAS_BRAIN_FRONTIER_FETCHER_ENABLED', false),

        // THE PORTFOLIO OF SELF-IMPROVEMENT PATHS (data, not code). The brain ROTATES these so it always
        // seeks the highest leverage, never dries, never duplicates. Each executor_organ is a real class.
        'paths' => [
            ['id' => 'frontier-harvest', 'intent' => 'self_improvement', 'objective_kind' => 'research', 'lens' => 'mine the defined sites (trendshift/github/arxiv) for a frontier technique to port', 'executor_organ' => AtlasExternalBrainFrontierHarvestGovernanceRunner::class],
            ['id' => 'metrics-optimization', 'intent' => 'self_improvement', 'objective_kind' => 'optimization', 'lens' => 'pick the change that most moves a real measured metric, gated by causal effect+CI', 'executor_organ' => AtlasBrainCausalEffectGate::class],
            ['id' => 'pattern-design', 'intent' => 'self_improvement', 'objective_kind' => 'refactor', 'lens' => 'match a scope symptom to a known improvement pattern in the registry', 'executor_organ' => AtlasLoopPatternRegistry::class],
            ['id' => 'simulation-twin', 'intent' => 'self_improvement', 'objective_kind' => 'optimization', 'lens' => 'simulate candidates against scenarios, keep the best return', 'executor_organ' => AtlasLoopSimulableTwinOrchestrator::class],
            ['id' => 'comprehension-deepening', 'intent' => 'self_improvement', 'objective_kind' => 'verification', 'lens' => 'go deeper on a subsystem to find a non-obvious structural leverage', 'executor_organ' => AtlasLoopScopeComprehensionModelBuilder::class],
            ['id' => 'adversarial-critique', 'intent' => 'self_improvement', 'objective_kind' => 'verification', 'lens' => 'attack a gate/organ to find a real hole, then author its fix', 'executor_organ' => AtlasLoopAdversarialVerifierPool::class],
            ['id' => 'compounding', 'intent' => 'self_improvement', 'objective_kind' => 'self_improvement', 'lens' => 'combine proven deliveries into a frontier jump; learn from outcomes', 'executor_organ' => AtlasLoopLearningAppendService::class],
        ],
    ],

    'storage_path' => env('ATLAS_STORAGE_PATH', '/var/atlas/storage'),
    'max_upload_bytes' => (int) env('ATLAS_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),

    // AGENT GOVERNANCE — the fleet control plane (desired-state + the babá/reconciler).
    'agents' => [
        // The standing babá. DEFAULT OFF (fail-closed): the scheduled reconcile is INERT until the operator
        // arms it. Even armed, its START actions are hard-gated (loop/fleet master, both default OFF), so it
        // can only ever reduce unsanctioned spend by default. Flip with ATLAS_AGENTS_RECONCILER_ENABLED=true.
        'reconciler_enabled' => (bool) env('ATLAS_AGENTS_RECONCILER_ENABLED', false),
    ],

    // AAEL. Every key here was already READ by the code and declared NOWHERE, so
    // each one silently took its inline default forever. The worst of them:
    // atlas:aael:parallel prints "set config atlas.aael.parallel.cli_enabled=true
    // to enable" — an instruction the operator could not follow, because there
    // was no section to set it in. Defaults below are byte-identical to the
    // inline ones, so declaring them changes nothing except making them reachable.
    'aael' => [
        'parallel' => [
            // DEFAULT OFF (fail-closed): every action exits 0 with a disabled notice.
            'cli_enabled' => (bool) env('ATLAS_AAEL_PARALLEL_CLI_ENABLED', false),
            'ledger_path' => env('ATLAS_AAEL_PARALLEL_LEDGER_PATH'),
        ],
        'inflight' => [
            'ledger_path' => env('ATLAS_AAEL_INFLIGHT_LEDGER_PATH'),
        ],
        'trace' => [
            'root' => env('ATLAS_AAEL_TRACE_ROOT'),
        ],
    ],

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
        'timeout_seconds' => (int) env('WHISPER_TIMEOUT_SECONDS', 7200),
        'normalize_timeout_seconds' => (int) env('WHISPER_NORMALIZE_TIMEOUT_SECONDS', 1200),
        // Anti-hallucination guards for the whisper-cli decoder (consumed by WhisperTranscriber).
        'max_context' => (int) env('WHISPER_MAX_CONTEXT', 0),
        'entropy_thold' => env('WHISPER_ENTROPY_THOLD', '2.4'),
        'no_speech_thold' => env('WHISPER_NO_SPEECH_THOLD', '0.6'),
        'suppress_non_speech' => (bool) env('WHISPER_SUPPRESS_NON_SPEECH', true),
    ],

    'marketing' => [
        // Max transcript chars fed verbatim to EACH extraction pass. Above this (a ~2h+ VSL), the
        // extractor keeps the head (hook/lead/mechanism) and tail (offer/price/CTA) intact and marks
        // the omitted middle, so a long VSL never silently loses its pitch. ~80k chars ≈ 20k tokens.
        'extraction_max_transcript_chars' => (int) env('ATLAS_MARKETING_EXTRACTION_MAX_TRANSCRIPT_CHARS', 80000),

        // ConversionCriticGate (generator+verifier): the bridge composer re-rolls / surfaces a refusal
        // when the FINAL post-override copy fails a STRUCTURAL-TRUTH floor (reveal/CTA leak in the
        // opening, choice-overload/no-CTA, zero concrete proof). Provider-free. Off = byte-identical to
        // the pre-critic baseline (dark-ship safe). Threshold tunes the WARN-only prior tier
        // (strong=70 / decent=55 / off=structural-only); structural floors block regardless.
        'critic_enabled' => (bool) env('ATLAS_MARKETING_CRITIC_ENABLED', true),
        'critic_threshold' => env('ATLAS_MARKETING_CRITIC_THRESHOLD', 'decent'),

        // Where the engine stores per-offer creative assets (producer before/after, generated images).
        'assets_root' => env('ATLAS_MARKETING_ASSETS_ROOT', storage_path('app/marketing/assets')),

        // Pluggable image-generation backend for LEGITIMATE creatives (product mockup, mechanism
        // illustration, lifestyle, thumbnail bg) — never result-proof. Off until the operator wires a
        // provider endpoint + key via env; off = the pipeline simply skips generated creatives.
        'image_generation' => [
            'enabled' => (bool) env('ATLAS_MARKETING_IMAGE_GEN_ENABLED', false),
            'endpoint' => env('ATLAS_MARKETING_IMAGE_GEN_ENDPOINT', ''),
            'api_key' => env('ATLAS_MARKETING_IMAGE_GEN_KEY', ''),
            'response_path' => env('ATLAS_MARKETING_IMAGE_GEN_RESPONSE_PATH', 'data.0.url'),
            'size' => env('ATLAS_MARKETING_IMAGE_GEN_SIZE', '1024x1024'),
            'timeout' => (int) env('ATLAS_MARKETING_IMAGE_GEN_TIMEOUT', 60),
        ],
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
    // G0-G8 admission chokepoint over memory writes. Advertised as a two-mode
    // gate (observe -> enforce), but atlas.memory_admission.mode was declared
    // nowhere, so every production call fell through to 'observe' and only
    // tests ever reached 'enforce' via config()->set(). blocks_write could not
    // be true outside a test.
    //
    // Default stays 'observe' — declaring the key changes nothing today, it
    // just makes the enforce mode reachable at all.
    'memory_admission' => [
        'mode' => (string) env('ATLAS_MEMORY_ADMISSION_MODE', 'observe'),
    ],

    'memory_conflict' => [
        'verb_classifier_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_VERB_CLASSIFIER_ENABLED', false),
        'axis_resolver_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_AXIS_RESOLVER_ENABLED', false),
        'scope_contradiction_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_SCOPE_CONTRADICTION_ENABLED', false),
        'fact_polarity_contradiction_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_FACT_POLARITY_CONTRADICTION_ENABLED', false),
        'numeric_range_overlap_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_NUMERIC_RANGE_OVERLAP_ENABLED', false),
        'temporal_supersession_enabled' => (bool) env('ATLAS_MEMORY_CONFLICT_TEMPORAL_SUPERSESSION_ENABLED', false),
    ],

    // MAXH-03 — Memory consolidation scanner (observe-mode producer for the 6 kernels).
    // The scanner instantiates the classifier kernels DIRECTLY on the pair it evaluates
    // (never flipping the global `memory_conflict.*` flags above), then appends an
    // append-only JSONL proposal ledger. Observe writes 0 relation rows by contract.
    // ATLAS_MEMORY_CONSOLIDATION_LEDGER_ROOT lets tests point the ledger at a tmp dir so
    // phpunit never touches the live ASI-05 ledger.
    'memory_consolidation' => [
        'ledger_root' => env(
            'ATLAS_MEMORY_CONSOLIDATION_LEDGER_ROOT',
            storage_path('atlas-local/memory-consolidation'),
        ),
        // MAXH-07 — redundant cluster synthesis (many active rows -> one canonical row).
        // Default OFF: the pair scanner remains byte-safe until a real qualified
        // cluster soak exists. When ON, clusters are sourced only from real scanner
        // proposals + StrategicForgetting `compress` decisions; simulated markers
        // are refused even in enforce mode.
        'cluster_synthesis_enabled' => (bool) env('ATLAS_MEMORY_CLUSTER_SYNTHESIS_ENABLED', false),
        'cluster_synthesis_min_members' => (int) env('ATLAS_MEMORY_CLUSTER_SYNTHESIS_MIN_MEMBERS', 3),
        'cluster_synthesis_author_engine_id' => env('ATLAS_MEMORY_CLUSTER_SYNTHESIS_AUTHOR_ENGINE_ID', 'cursor-acos-max-maxh07-cluster-author'),
        'cluster_synthesis_judge_engine_id' => env('ATLAS_MEMORY_CLUSTER_SYNTHESIS_JUDGE_ENGINE_ID', 'codex-independent-maxh07-cluster-judge'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim-coherence cognitive kernels
    |--------------------------------------------------------------------------
    | Pure deterministic kernels under App\Services\Ai\Learning\ClaimCoherence\,
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
        // Obra 2 / MEM-02: ON with write-back consumer wired (byte-safe when signals empty).
        'promotion_gate_evaluator_enabled' => (bool) env('ATLAS_COGNITIVE_IMMUNE_PROMOTION_GATE_EVALUATOR_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Elite kernel honesty (AiWorker)
    |--------------------------------------------------------------------------
    | Obra 2 / DEV-01: assertEliteKernelHonestOutcome fails closed by default.
    | Emergency escape: ATLAS_ELITE_KERNEL_HONESTY_FAIL_OPEN=true (advisory only).
    */
    'elite_kernel' => [
        'honesty_fail_open' => (bool) env('ATLAS_ELITE_KERNEL_HONESTY_FAIL_OPEN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Programming orchestrator RAG gate
    |--------------------------------------------------------------------------
    | Obra 2 / RAG: honor context_sufficiency_gate.blocks_execution on strict flows.
    */
    'programming' => [
        'enforce_rag_gate' => (bool) env('ATLAS_PROGRAMMING_ENFORCE_RAG_GATE', true),
        // Obra 2 / CTX-01: Dev discovery prefers AtlasContextRuntime::compose (legacy fallback).
        'context_compose_enabled' => (bool) env('ATLAS_PROGRAMMING_CONTEXT_COMPOSE_ENABLED', true),
        // Obra 4 / CTX-04: ACFQ freshness blockers hard-fail AUCRI on elite Dev/Forge/TaskServing paths.
        'strict_retrieval_gate' => (bool) env('ATLAS_ELITE_STRICT_RETRIEVAL_GATE', true),
        // Obra 5 / DEV-04: sovereign floor on PipelineRunExecutor completion receipts.
        'sovereign_floor_enforced' => (bool) env('ATLAS_PROGRAMMING_SOVEREIGN_FLOOR_ENFORCED', true),
        // Obra 5 / DEV-05: fail-closed when AtlasContextRuntime certify blocks gateway dispatch.
        'context_runtime_fail_closed' => (bool) env('ATLAS_PROGRAMMING_CONTEXT_RUNTIME_FAIL_CLOSED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Engineering kernel (ENG-02 / ENG-14)
    |--------------------------------------------------------------------------
    | Forge sovereign execution gate: OBSERVE by default (records verdict, never
    | blocks). Flip ATLAS_FORGE_EXECUTION_GATE_ENFORCE=true only after ENG-14
    | readiness (promoted harness_captured volume + operator OK).
    */
    'engineering_kernel' => [
        'forge_execution_gate_enforcing' => (bool) env('ATLAS_FORGE_EXECUTION_GATE_ENFORCE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | ACOS Context Runtime unified retrieval
    |--------------------------------------------------------------------------
    | Kill switch OFF preserves the legacy Builder + Injection retrieval paths.
    | Mode ladder: offline → shadow → canary → default. Shadow builds the fused
    | pack for receipt/compare but keeps legacy injection. Canary/default hand
    | the fused pack to injection. Legacy enabled=true with mode=offline is
    | treated as default (see AtlasIntelligenceRolloutMode).
    */
    'context_runtime' => [
        'unified_retrieval_enabled' => (bool) env('ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_ENABLED', false),
        'unified_retrieval_mode' => (string) env('ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_MODE', 'offline'),
        'unified_retrieval_canary_percent' => max(0, min(100, (int) env('ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_CANARY_PERCENT', 0))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atlas Decide learned-route gateway consultation
    |--------------------------------------------------------------------------
    | Every automatic gateway request records a governed consultation. Shadow
    | mode is the default and never changes the selected provider. Active mode
    | (alias of default) can only follow an operator-activated route that passes
    | Kernel/Admission and points to a live auto-worker provider. Offline skips
    | consultation entirely (kill switch or explicit mode).
    */
    'atlas_decide' => [
        'gateway_consultation_enabled' => (bool) env('ATLAS_DECIDE_GATEWAY_CONSULTATION_ENABLED', true),
        'gateway_consultation_mode' => env('ATLAS_DECIDE_GATEWAY_CONSULTATION_MODE', 'shadow'),

        // MULTK-07 — derive requested_autonomy from evidence, MONOTONICALLY
        // DOWNWARD from 'autonomous' (machine tightens, never loosens).
        // Default-OFF: consultation stays byte-identical until the flag flips.
        'requested_autonomy_shrink_enabled' => (bool) env('ATLAS_DECIDE_REQUESTED_AUTONOMY_SHRINK_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | AEMOR engineering outcome recorder rollout
    |--------------------------------------------------------------------------
    | Kill switch OFF = no-op. Mode ladder: offline → shadow → canary → default.
    | shadow = open/observe/close + judge, no distill. canary/default = full
    | path. Defaults keep Forge/Autônomos recording; flip enabled=false to drain.
    */
    'aemor' => [
        'engineering_outcome_enabled' => (bool) env('ATLAS_AEMOR_ENGINEERING_OUTCOME_ENABLED', true),
        'engineering_outcome_mode' => (string) env('ATLAS_AEMOR_ENGINEERING_OUTCOME_MODE', 'default'),
        'engineering_outcome_canary_percent' => max(0, min(100, (int) env('ATLAS_AEMOR_ENGINEERING_OUTCOME_CANARY_PERCENT', 0))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Engineering run conductor (OUTC-01)
    |--------------------------------------------------------------------------
    | LIVE runs feed the compounding pipeline by default; set compound=false to
    | opt out per run. SHADOW never compounds regardless.
    */
    'engineering_conductor' => [
        'compound_default_live' => (bool) env('ATLAS_ENGINEERING_CONDUCTOR_COMPOUND_DEFAULT_LIVE', true),
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
    |   unrecoverable-overflow blockers. When ON it writes a structured shadow
    |   JSONL record outside the provider payload/hash; delivery stays byte-identical.
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
        'must_keep_allocator_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_MUST_KEEP_ALLOCATOR_ENABLED', true),
        'must_keep_allocator_shadow_disk' => env('ATLAS_CONTEXT_BUDGET_MUST_KEEP_ALLOCATOR_SHADOW_DISK', 'local'),
        'must_keep_allocator_shadow_path' => env('ATLAS_CONTEXT_BUDGET_MUST_KEEP_ALLOCATOR_SHADOW_PATH', 'atlas/context-budget/must-keep-shadow.jsonl'),
        'recall_split_scorer_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_RECALL_SPLIT_SCORER_ENABLED', false),
        'retrieval_fanout_gate_enabled' => (bool) env('ATLAS_CONTEXT_BUDGET_RETRIEVAL_FANOUT_GATE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context ranking feedback (ARFL -> ACRS closed loop)
    |--------------------------------------------------------------------------
    | feedback_global_hints (Obra #13 item 4): when a rank() call has NO
    | flow_id, ACRS (AtlasContextRankingSystemService::feedbackHint) aggregates
    | the persisted ai_rag_feedback_events of the last 7 days (cap 50) into
    | GLOBAL demote/repromote hints; a source type / ref hash only acts when it
    | repeats across >=2 events. The flow_id-scoped path is untouched. The rank
    | report gains source_ranking_inputs.feedback_hint.feedback_scope
    | (flow|global|none). OFF => byte-identical pre-loop output (no global
    | hints, no feedback_scope field).
    */
    'context' => [
        'feedback_global_hints' => (bool) env('ATLAS_CONTEXT_FEEDBACK_GLOBAL_HINTS', true),
        // Obra 7 / OPT-04: ARFL→ACRS repromote on programming flow_ids (atlas_dev, atlas_forge, …).
        'programming_flow_repromote_enabled' => (bool) env('ATLAS_CONTEXT_PROGRAMMING_FLOW_REPROMOTE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token economy (ATER) — Obra 7 / OPT-08
    |--------------------------------------------------------------------------
    | observe = advisory receipts only (default, fail-open).
    | shadow  = compute enforce decisions without blocking callers.
    | enforce = block provider calls when budget policy says so (elite-safe only).
    */
    'token_economy' => [
        'enforcement_mode' => (string) env('ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE', 'observe'),
    ],

    // MAXF-09 — L2 hierarchical summaries are local-only, asynchronous, verified
    // against L1 receipts, and never canonical. Both generation and consumption
    // start OFF; enabling consumption only changes read surfaces that explicitly
    // call the L2 reader.
    'compaction' => [
        'l2_hierarchical_summary_generation_enabled' => (bool) env('ATLAS_COMPACTION_L2_HIERARCHICAL_SUMMARY_GENERATION_ENABLED', false),
        'l2_hierarchical_summary_consumption_enabled' => (bool) env('ATLAS_COMPACTION_L2_HIERARCHICAL_SUMMARY_CONSUMPTION_ENABLED', false),
        'l2_hierarchical_summary_queue_connection' => (string) env('ATLAS_COMPACTION_L2_HIERARCHICAL_SUMMARY_QUEUE_CONNECTION', 'database-long'),
        'l2_hierarchical_summary_queue' => (string) env('ATLAS_COMPACTION_L2_HIERARCHICAL_SUMMARY_QUEUE', 'compaction-l2'),
        'l2_hierarchical_summary_evidence_path' => (string) env(
            'ATLAS_COMPACTION_L2_HIERARCHICAL_SUMMARY_EVIDENCE_PATH',
            storage_path('app/atlas/evidence/l2-hierarchical-summaries.jsonl'),
        ),
    ],

    'memory' => [
        // ACOS FEE-04: land default-OFF; flip only after watchdog soak + rollback trigger.
        'feedback_ranking_enabled' => (bool) env('ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED', false),

        // MAXB-09: recall cache by query_hash (usage-safe).
        // TTL is clamped to [60, 900] seconds by AtlasMemoryRecallCache to prevent
        // stale delivery beyond the spec window.
        'recall_cache' => [
            'enabled' => (bool) env('ATLAS_MEMORY_RECALL_CACHE_ENABLED', true),
            'ttl_seconds' => (int) env('ATLAS_MEMORY_RECALL_CACHE_TTL_SECONDS', 600),
        ],
    ],

    // MAXH-02 — default temporal truth derivation for Atlas Memory. These are
    // type-map defaults, tagged as `default_type_map`, and therefore do not count
    // as non-default temporal provenance in MAXH-01.
    'memory_temporal_defaults' => [
        'authority_by_type' => [
            'decision' => 'canonical',
            'preference' => 'operator',
            'feedback' => 'operational',
            'technical_context' => 'operational',
            'issue' => 'operational',
            'resolution' => 'operational',
            'benchmark_observation' => 'measured',
            'harness_learning' => 'measured',
            'anti_memory' => 'safety',
            'strategic_insight' => 'strategic',
            'refutation_memory' => 'canonical',
        ],
        // Null TTL means the type is durable until superseded/retracted. Decaying
        // types are operational observations whose truth changes with runtime.
        'ttl_days_by_type' => [
            'decision' => null,
            'preference' => null,
            'feedback' => 180,
            'technical_context' => 90,
            'issue' => 90,
            'resolution' => 180,
            'benchmark_observation' => 45,
            'harness_learning' => 120,
            'anti_memory' => null,
            'strategic_insight' => 180,
            'refutation_memory' => null,
        ],
    ],

    'semantic_memory' => [
        'vault_path' => env('ATLAS_VAULT_PATH', dirname(base_path()).'/AtlasVault'),
        'embedding_dimensions' => (int) env('ATLAS_SEMANTIC_EMBEDDING_DIMENSIONS', 384),
        'embedding_provider' => env('ATLAS_SEMANTIC_EMBEDDING_PROVIDER', 'semantic_rag'),
        'embedding_model' => env('ATLAS_SEMANTIC_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_api_key' => env('OPENAI_API_KEY'),
        'embedding_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_timeout_seconds' => (int) env('ATLAS_SEMANTIC_EMBEDDING_TIMEOUT_SECONDS', 20),
        'embedding_fallback_enabled' => (bool) env('ATLAS_SEMANTIC_EMBEDDING_FALLBACK_ENABLED', false),
        'embedding_cache_enabled' => (bool) env('ATLAS_SEMANTIC_EMBEDDING_CACHE_ENABLED', true),
        'embedding_cache_ttl_seconds' => (int) env('ATLAS_SEMANTIC_EMBEDDING_CACHE_TTL_SECONDS', 3600),
        'semantic_rag_model' => env('ATLAS_SEMANTIC_RAG_MODEL', 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'),
        'jina_v3_dual_read_ledger_path' => env(
            'ATLAS_SEMANTIC_JINA_V3_DUAL_READ_LEDGER',
            storage_path(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
        ),
        'embedding_daemon_enabled' => (bool) env('ATLAS_SEMANTIC_RAG_DAEMON_ENABLED', true),
        'embedding_daemon_auto_start' => (bool) env('ATLAS_SEMANTIC_RAG_DAEMON_AUTO_START', true),
        'embedding_daemon_socket_path' => env(
            'ATLAS_SEMANTIC_RAG_DAEMON_SOCKET',
            sys_get_temp_dir().'/atlas-semantic-rag-'.substr(sha1(base_path()), 0, 12).'.sock'
        ),
        'embedding_daemon_manifest_path' => env(
            'ATLAS_SEMANTIC_RAG_DAEMON_MANIFEST',
            storage_path('atlas/semantic-rag-daemon/manifest.json')
        ),
        'embedding_daemon_connect_timeout_ms' => (int) env('ATLAS_SEMANTIC_RAG_DAEMON_CONNECT_TIMEOUT_MS', 200),
        'embedding_daemon_startup_timeout_ms' => (int) env('ATLAS_SEMANTIC_RAG_DAEMON_STARTUP_TIMEOUT_MS', 1000),
        'embedding_daemon_idle_timeout_seconds' => (int) env('ATLAS_SEMANTIC_RAG_DAEMON_IDLE_TIMEOUT_SECONDS', 300),
        // R1: embed atlas_memory_entries + atlas_verbatim_memories on write and
        // rank recall by real pgvector similarity (pgsql-only; falls back to the
        // lexical path on sqlite / when the embedding engine is unavailable).
        'memory_vector_recall_enabled' => (bool) env('ATLAS_SEMANTIC_MEMORY_VECTOR_RECALL_ENABLED', true),
        // ACDE #3 — compounding-recall arm: surface PROMOTED compounding learnings (AiCompoundingMemory, the
        // approved learning store) into the SAME hybrid recall the live provider injection consumes, so every
        // session reads what the loop already learned from prior runs/merges. Default-ON is a conscious
        // chicken-egg inversion: lift is measurable only once the arm serves; attribution still requires
        // explicit `used`, and only gate-promoted active memories can appear.
        'compounding_recall_enabled' => (bool) env('ATLAS_HYBRID_RECALL_INCLUDE_COMPOUNDING', true),
        // RAG-02: retrievalEvalCounts window pinned in config (not env) — 30d honest floor.
        'retrieval_eval_window_days' => 30,
        'compounding_recall_limit' => max(0, (int) env('ATLAS_HYBRID_RECALL_COMPOUNDING_LIMIT', 6)),
        'compounding_recall_min_confidence' => max(0, (int) env('ATLAS_HYBRID_RECALL_COMPOUNDING_MIN_CONFIDENCE', 0)),
        // RAG-02: retrieval quality is a recent operational health signal. Keep
        // all-time audit counts separately; do not env-arm the scoring window.
        // Obra 5 / MEM-04 + OPT-01: demote dominant recall entries on the live hybrid path.
        'recall_concentration_demotion_enabled' => (bool) env('ATLAS_MEMORY_RECALL_CONCENTRATION_DEMOTION', true),
        'recall_concentration_window_days' => max(1, (int) env('ATLAS_MEMORY_RECALL_CONCENTRATION_WINDOW_DAYS', 45)),
        'recall_concentration_demote_ratio' => (float) env('ATLAS_MEMORY_RECALL_CONCENTRATION_DEMOTE_RATIO', 0.35),
        'recall_concentration_min_recalls' => max(10, (int) env('ATLAS_MEMORY_RECALL_CONCENTRATION_MIN_RECALLS', 100)),
        'recall_concentration_score_factor' => (float) env('ATLAS_MEMORY_RECALL_CONCENTRATION_SCORE_FACTOR', 0.35),
        // MAXH-05 — soft temporal truth demotion in recall. Default OFF keeps ranking byte-identical.
        // When ON, stale/expired/superseded rows are demoted but remain recoverable with explain flags.
        'temporal_recall_demotion_enabled' => (bool) env('ATLAS_MEMORY_TEMPORAL_RECALL_DEMOTION_ENABLED', false),
        // MAXB-04 — MMR top-K after score-sort / before greedy budget. Default OFF = byte-identical.
        'mmr_top_k_enabled' => (bool) env('ATLAS_MEMORY_MMR_TOP_K_ENABLED', false),
        'mmr_lambda' => (float) env('ATLAS_MEMORY_MMR_LAMBDA', 0.7),
        // MAXB-05 — mined_negative labels from gate/forget/curate. Default OFF; never feeds FEEDBACK_NEGATIVE.
        'mined_negative_feedback_enabled' => (bool) env('ATLAS_MEMORY_MINED_NEGATIVE_FEEDBACK_ENABLED', false),
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
        // Obra 6 / RAG-05: elite Dev/Forge/TaskServing paths prefer full ASEF→AHRI depth.
        'elite_semantic_depth' => (bool) env('ATLAS_AUCRI_ELITE_SEMANTIC_DEPTH', true),
        // Obra 6 / RAG-06: persist AREBA run summaries for regression baseline (JSONL).
        'arena_persist_runs' => (bool) env('ATLAS_AUCRI_ARENA_PERSIST_RUNS', true),
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
        'live_activities' => [
            // Só habilite depois de registrar uma chave APNs no cofre de
            // segredos. Sem as três credenciais, o serviço é no-op honesto.
            'enabled' => (bool) env('ATLAS_LIVE_ACTIVITIES_ENABLED', false),
            'apns_key_id' => env('ATLAS_LIVE_ACTIVITIES_APNS_KEY_ID'),
            'apns_team_id' => env('ATLAS_LIVE_ACTIVITIES_APNS_TEAM_ID'),
            'apns_private_key' => env('ATLAS_LIVE_ACTIVITIES_APNS_PRIVATE_KEY'),
            'topic' => env('ATLAS_LIVE_ACTIVITIES_APNS_TOPIC', 'com.vitor.atlas.native.push-type.liveactivity'),
            'start_topic' => env('ATLAS_LIVE_ACTIVITIES_APNS_START_TOPIC', 'com.vitor.atlas.native.push-type.liveactivity'),
            'minimum_update_interval_seconds' => (int) env('ATLAS_LIVE_ACTIVITIES_MIN_UPDATE_INTERVAL_SECONDS', 5),
            'timeout_seconds' => (int) env('ATLAS_LIVE_ACTIVITIES_TIMEOUT_SECONDS', 8),
        ],
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

        // Provider spend sentinel. All three keys were already read — enabled by
        // AtlasSwarmServiceProvider, the two ceilings by AtlasProviderCostSentinel
        // — and declared nowhere, so `atlas:cost:calibrate` told the operator to
        // "enable atlas.ai.cost_sentinel.enabled" and to "set
        // atlas.ai.cost_sentinel.hard_gate_units [...] to flip from observe to
        // enforce" against keys that did not exist to be set.
        //
        // Defaults below are byte-identical to the inline ones: OFF, and both
        // ceilings at 0.0 — the hard gate only bites above 0.0, so observe stays
        // the behaviour until the operator chooses a ceiling.
        'cost_sentinel' => [
            'enabled' => (bool) env('ATLAS_AI_COST_SENTINEL_ENABLED', false),
            'soft_warn_units' => (float) env('ATLAS_AI_COST_SENTINEL_SOFT_WARN_UNITS', 0.0),
            'hard_gate_units' => (float) env('ATLAS_AI_COST_SENTINEL_HARD_GATE_UNITS', 0.0),
        ],

        'default_provider' => env('ATLAS_AI_DEFAULT_PROVIDER', 'hermes_cli'),
        'default_tier' => env('ATLAS_AI_DEFAULT_TIER', 'daily'),
        'council_allow_auto' => (bool) env('ATLAS_AI_COUNCIL_ALLOW_AUTO', false),
        'handoff_skip_providers' => array_values(array_filter(array_map(
            static fn (string $provider): string => trim($provider),
            explode(',', (string) env('ATLAS_AI_HANDOFF_SKIP_PROVIDERS', 'claude_codex')),
        ))),

        // Hyperflow organ: AtlasAiRouterService consumes the RouterRuntime
        // flow decision (payload.hyperflow_runtime) for auto-routing instead
        // of re-deriving one from keyword heuristics. Operator-explicit
        // branches (slash command, atlas_code surface, programming mode)
        // always win; heuristics remain the fallback when the envelope is
        // absent/errored or resolved to the conversation fallback.
        'router' => [
            'consume_hyperflow_runtime' => (bool) env('ATLAS_AI_ROUTER_CONSUME_HYPERFLOW', true),
        ],

        // Árbitro SEMÂNTICO de flow (S53): quando o léxico cai no fallback,
        // um modelo LOCAL (hermes one-shot) lê a mensagem contra o catálogo
        // de flows e escolhe o destino — roda no WORKER (latência invisível
        // no job assíncrono), validado contra catálogo fechado, fail-open.
        // {@see AtlasSemanticFlowArbiterService}
        'semantic_arbiter' => [
            'enabled' => (bool) env('ATLAS_AI_SEMANTIC_ARBITER_ENABLED', true),
            'binary' => env('ATLAS_AI_SEMANTIC_ARBITER_BINARY', 'hermes'),
            'timeout_seconds' => (int) env('ATLAS_AI_SEMANTIC_ARBITER_TIMEOUT_SECONDS', 60),
        ],

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

        // MAXK-05 — property-gated signature verification for the autonomy ladder.
        // The auto-apply gate (`AtlasAutonomousLearningApplier::decideCandidate`)
        // interrogates the append-only ledger at `signature_ledger_path` when
        // `signature_verification_enabled` is ON. A promotion_gate that lists
        // `signatures` MUST carry receipt objects `{actor,nonce,policy_hash}`,
        // never bare booleans — booleans are the adversarial input the MAXK-05
        // test forges. Absent `signatures` payload = legacy shape, byte-safe.
        'autonomy_ladder' => [
            'signature_verification_enabled' => (bool) env('ATLAS_AUTONOMY_LADDER_SIGNATURE_VERIFICATION_ENABLED', true),
            'signature_ledger_path' => env(
                'ATLAS_AUTONOMY_LADDER_SIGNATURE_LEDGER_PATH',
                storage_path('atlas-local/autonomy-ladder/signature-ledger.ndjson'),
            ),

            // MAXK-06 — sealed JSONL ledger the metrics authority reads from
            // to break the "caller supplies the numbers that decide the
            // promotion" forgery. Only the ASI-05 telemetry sealer writes;
            // the promotion gate only reads and refuses non-verified entries.
            'metrics_authority_ledger_path' => env(
                'ATLAS_AUTONOMY_LADDER_METRICS_AUTHORITY_LEDGER_PATH',
                storage_path('atlas-local/autonomy-ladder/metrics-authority.ndjson'),
            ),
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

        // ASI-09 — Distiller author≠judge seam. When ON, the AtlasLearningDistiller
        // asks the bound DistillerAuthorAdapter to AUTHOR the claim from
        // outcome+signals; the JUDGES (capture_quality_gate + false_learning_gate +
        // ASI-02 admission + confidence floor) remain 100% deterministic and untouched.
        // Default OFF ⇒ byte-identical to the template author (degrade honesto).
        // Sensitive/secret classes: the adapter itself is responsible for keeping the
        // authoring LOCAL (Hermes/GLM) — the distiller never routes payload to a
        // provider by itself.
        'distiller' => [
            'model_author_enabled' => (bool) env('ATLAS_DISTILLER_MODEL_AUTHOR_ENABLED', false),
        ],

        // MAXJ-02 — candidate-local credit assignment. Default OFF keeps the
        // AtlasLearningDistiller template payload byte-identical; when ON it
        // writes payload.caused_by using the deterministic local causal
        // attributor only. This never touches falseLearningGate/AEMOR.
        'credit_assignment' => [
            'enabled' => (bool) env('ATLAS_AI_CREDIT_ASSIGNMENT_ENABLED', false),
        ],

        // MULTJ-06 — abstraction ladder enqueue. Default OFF keeps propose()
        // read-only; when ON, `atlas:ai:abstraction-ladder --enqueue` may
        // materialise level-3 principles into ai_learning_candidates (same
        // queue / ASI-02 door — never a parallel queue). Pack injection seam
        // remains separate (`pack_injection_enabled`, also default-OFF).
        'abstraction_ladder' => [
            'enqueue_enabled' => (bool) env('ATLAS_AI_ABSTRACTION_LADDER_ENQUEUE_ENABLED', false),
            'pack_injection_enabled' => (bool) env('ATLAS_AI_ABSTRACTION_LADDER_PACK_INJECTION_ENABLED', false),
        ],

        // MULTJ-04 — procedural playbook -> skill.v1 promoter. Default OFF:
        // reports the mechanism + soak window, and only materialises floor-met
        // proposals into the same ASI-02 held queue when explicitly enabled.
        'procedural_skill_promoter' => [
            'enqueue_enabled' => (bool) env('ATLAS_AI_PROCEDURAL_SKILL_PROMOTER_ENQUEUE_ENABLED', false),
        ],

        // MAXJ-07 — co-recall composition detector → ASI-02 held queue. Default OFF.
        'co_recall_composition' => [
            'enabled' => (bool) env('ATLAS_AI_CO_RECALL_COMPOSITION_ENABLED', false),
            'enqueue_enabled' => (bool) env('ATLAS_AI_CO_RECALL_COMPOSITION_ENQUEUE_ENABLED', false),
            'floor' => max(3, (int) env('ATLAS_AI_CO_RECALL_COMPOSITION_FLOOR', 8)),
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
        // LEGACY ACDE residual (not live operate). Live Autônomos = brain/task.
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
                // ENG-10 — advisory would-have-blocked meter. When 0, the candidate
                // ceiling is DERIVED from the p99 of observed pre_cost_units in the
                // provider coverage ledger (never a hand-picked zero). Override only
                // for operator tuning or tests; production flip (ENG-13) uses the
                // derived/validated candidate, not a guess.
                'hard_units_candidate' => (float) env('ATLAS_AI_CALL_COST_GUARD_HARD_UNITS_CANDIDATE', 0),
                // Percentile used when deriving the candidate from ledger traffic.
                'hard_units_candidate_percentile' => (float) env('ATLAS_AI_CALL_COST_GUARD_HARD_UNITS_CANDIDATE_PERCENTILE', 0.99),
            ],
        ],

        // SLICE 2 — shared provider-governance seam. The muscle paths (Forge/loop
        // CLI, Dev claude) consult the SAME cost-guard + ADML the manager runs
        // BEFORE they spawn, WITHOUT being forced through AiProviderManager::get()
        // (which would break streaming/worktree). ADVISORY by default: the consult
        // only measures + records coverage. Flip enforce ON (WITH a
        // cache.cost_guard.hard_units > 0) to let the cost guard actually BLOCK a
        // would-be-too-expensive spawn. Default OFF => byte-identical to today.
        'governance' => [
            'enforce' => (bool) env('ATLAS_AI_GOVERNANCE_ENFORCE', false),
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
        // P2b-CANARY: percent of NEW decision issuances that attach a companion receipt_v3
        // envelope. Default 0 = writers remain legacy V2-only. Never rewrites historical V2.
        'decision_receipt_v3_canary_percent' => max(0, min(100, (int) env('ATLAS_AI_DECISION_RECEIPT_V3_CANARY_PERCENT', 0))),
        // P2b-CUTOVER writer selector: when true, new issuances attach the V3 CUTOVER-shaped
        // companion. It never changes reader authority: V2 governs a dual transport and V3-only
        // remains fail-closed until independently bound runtime authority exists. Historical V2 is untouched.
        'decision_receipt_v3_cutover_enabled' => (bool) env('ATLAS_AI_DECISION_RECEIPT_V3_CUTOVER_ENABLED', false),
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
                // R104-TRANSPORT: when true, Hermes surfaces may advertise the
                // native_function_call capability (transport attestation — never
                // model-name suffix). Atlas declares atlas_apply_patch and lifts
                // structured tool_calls from Hermes output into provider metadata
                // for AgentExecutionProviderPortAdapter packaging. Default OFF so
                // free_form remains the honest live channel until LIVE proof.
                'native_fc' => [
                    'enabled' => (bool) env('ATLAS_AI_HERMES_NATIVE_FC_ENABLED', false),
                ],
                // Non-interactive one-shot CLI mode. `hermes chat` is the INTERACTIVE
                // subcommand and blocks waiting on input without a TTY — the exact hang
                // that stalled the autonomous loop (every grind ate the full attempt
                // budget with zero output). The top-level `hermes -z PROMPT` one-shot is
                // non-interactive ("intended for scripts / pipes", approvals auto-bypassed)
                // and still loads config.yaml/tools/memory normally, so the model fallback
                // chain + reasoning_effort + max_turns are honored from config. Forge
                // provider invocations (loop/missions) carry a per-call env the warm ACP
                // pool can't reuse, so they MUST take this CLI path → default them to
                // one-shot. Other CLI callers stay on `chat` unless this is turned on.
                // Session continuity (resume/continue) always forces `chat` regardless.
                'cli_oneshot_for_forge' => (bool) env('ATLAS_AI_HERMES_CLI_ONESHOT_FOR_FORGE', true),
                'cli_oneshot' => (bool) env('ATLAS_AI_HERMES_CLI_ONESHOT', false),
                'model' => env('ATLAS_AI_HERMES_MODEL', 'qwen3.6-27b'),
                'model_label' => env('ATLAS_AI_HERMES_MODEL_LABEL', env('ATLAS_AI_HERMES_MODEL') ?: 'Verboo Qwen 3.6 27B'),
                'model_tier' => env('ATLAS_AI_HERMES_MODEL_TIER', 'executive_runtime'),
                'model_identity' => env('ATLAS_AI_HERMES_MODEL_IDENTITY', env('ATLAS_AI_HERMES_MODEL') ?: 'qwen3.6-27b'),
                'fallback_model' => null,
                'allow_auto' => (bool) env('ATLAS_AI_HERMES_ALLOW_AUTO', true),
                'allow_manual' => (bool) env('ATLAS_AI_HERMES_ALLOW_MANUAL', true),
                'provider' => env('ATLAS_AI_HERMES_PROVIDER', 'verboo'),
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
                // Workcell Adapter (Atlas Orchestrator Canon): the CANONICAL,
                // provider-neutral config for the governed many-agent fan-out — same
                // switches as the retired `mesh` block above, default-off. Each key
                // reads its WORKCELL env first, then falls back to the legacy MESH env
                // so operators who configured the old keys keep working unchanged.
                // New code reads `workcell.*`; `mesh` survives only as a compat alias.
                'workcell' => [
                    'policy' => env('ATLAS_AI_HERMES_WORKCELL_POLICY', env('ATLAS_AI_HERMES_MESH_POLICY', 'off')),
                    'auto_route' => (bool) env('ATLAS_AI_HERMES_WORKCELL_AUTO_ROUTE', env('ATLAS_AI_HERMES_MESH_AUTO_ROUTE', false)),
                    'max_parallel_workers' => (int) env('ATLAS_AI_HERMES_WORKCELL_MAX_PARALLEL', env('ATLAS_AI_HERMES_MESH_MAX_PARALLEL', 8)),
                    'max_children' => (int) env('ATLAS_AI_HERMES_WORKCELL_MAX_CHILDREN', env('ATLAS_AI_HERMES_MESH_MAX_CHILDREN', 64)),
                    'checkpoint_policy' => env('ATLAS_AI_HERMES_WORKCELL_CHECKPOINT_POLICY', env('ATLAS_AI_HERMES_MESH_CHECKPOINT_POLICY', 'off')),
                    'worktree_fleet' => (bool) env('ATLAS_AI_HERMES_WORKCELL_WORKTREE_FLEET', env('ATLAS_AI_HERMES_MESH_WORKTREE_FLEET', true)),
                    'isolate_profile_home' => (bool) env('ATLAS_AI_HERMES_WORKCELL_ISOLATE_PROFILE_HOME', env('ATLAS_AI_HERMES_MESH_ISOLATE_PROFILE_HOME', false)),
                    'profiles' => [],
                    'poll_interval_microseconds' => (int) env('ATLAS_AI_HERMES_WORKCELL_POLL_US', env('ATLAS_AI_HERMES_MESH_POLL_US', 50000)),
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
    // EVI-09: ACOS delta series — série diária N×M (HOJE vs Marco Zero).
    // Config primária: atlas.acos.delta_series_enabled (fallback legado atlas.fable.*).
    'acos' => [
        'delta_series_enabled' => (bool) env('ATLAS_ACOS_DELTA_SERIES_ENABLED', env('ATLAS_FABLE_DELTA_SERIES_ENABLED', true)),

        // WDG-01 — unified watchdog check registry. External EVI-01 calls the
        // same CLI; the internal schedule below is exactly one fallback job.
        'watchdog' => [
            'enabled' => (bool) env('ATLAS_ACOS_WATCHDOG_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_ACOS_WATCHDOG_SCHEDULE_ENABLED', true),
            'schedule_cadence_minutes' => max(1, (int) env('ATLAS_ACOS_WATCHDOG_SCHEDULE_CADENCE_MINUTES', 15)),
        ],

        // ROL-01 — pre-declared objective rollback triggers for future ACOS flips.
        // Read-only check: atlas:acos:rollback-triggers --json (EVI-01 / WDG-01).
        'rollback_triggers' => [
            'enabled' => (bool) env('ATLAS_ACOS_ROLLBACK_TRIGGERS_CHECK_ENABLED', true),
            'flips' => [
                [
                    'id' => 'eng_13_governance_enforce',
                    'slices' => ['ENG-13'],
                    'condition' => [
                        'kind' => 'real_completion_blocked_in_window',
                        'window_hours' => 24,
                        'min_count' => 1,
                        'requires_flip' => [
                            'env' => 'ATLAS_AI_GOVERNANCE_ENFORCE',
                            'value' => true,
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_AI_GOVERNANCE_ENFORCE' => 'false',
                        'ATLAS_AI_CALL_COST_GUARD_HARD_UNITS' => '0',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
                [
                    'id' => 'eng_14_forge_gate_enforce',
                    'slices' => ['ENG-14'],
                    'condition' => [
                        'kind' => 'real_completion_blocked_in_window',
                        'window_hours' => 24,
                        'min_count' => 1,
                        'scope' => 'forge',
                        'requires_flip' => [
                            'env' => 'ATLAS_FORGE_EXECUTION_GATE_ENFORCE',
                            'value' => true,
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_FORGE_EXECUTION_GATE_ENFORCE' => 'false',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
                [
                    'id' => 'eng_15_adml_cost_outcome',
                    'slices' => ['ENG-15'],
                    'condition' => [
                        'kind' => 'proven_route_below_min_evidence',
                        'window_days' => 7,
                        'min_count' => 1,
                        'requires_flip' => [
                            'env' => 'ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED',
                            'value' => true,
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED' => 'false',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
                [
                    'id' => 'cpt_09_compaction_enforce',
                    'slices' => ['CPT-09'],
                    'condition' => [
                        'kind' => 'must_keep_critical_cut',
                        'min_count' => 1,
                        'requires_flip' => [
                            // Runtime key is atlas.token_economy.enforcement_mode
                            // (ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE). Do not use the
                            // dead ATLAS_COMPACTION_ENFORCEMENT_MODE alias.
                            'env' => 'ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE',
                            'value' => 'enforce',
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE' => 'observe',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
                [
                    'id' => 'ope_04_fee_04_pack_quality',
                    'slices' => ['OPE-04', 'FEE-04'],
                    'condition' => [
                        'kind' => 'pack_quality_below_baseline',
                        'baseline_window_days' => 7,
                        'max_score_drop' => 5,
                        'requires_flip' => [
                            'any_env' => [
                                ['env' => 'ATLAS_HYBRID_RECALL_INCLUDE_COMPOUNDING', 'value' => true],
                                ['env' => 'ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED', 'value' => true],
                            ],
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_HYBRID_RECALL_INCLUDE_COMPOUNDING' => 'false',
                        'ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED' => 'false',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
                [
                    'id' => 'pip_04_remint_touched',
                    'slices' => ['PIP-04'],
                    'condition' => [
                        'kind' => 'landing_latency_exceeded',
                        'budget_ms' => 900_000,
                        'requires_flip' => [
                            'env' => 'ATLAS_COGNITION_REMINT_TOUCHED_ENABLED',
                            'value' => true,
                        ],
                    ],
                    'rollback_action' => [
                        'ATLAS_COGNITION_REMINT_TOUCHED_ENABLED' => 'false',
                    ],
                    'executor' => 'watchdog_alert_operator_reverts',
                ],
            ],
        ],
    ],

    // Legado campanha Fable — mantido 1 ciclo para leitura de env antigo.
    'fable' => [
        'delta_series_enabled' => (bool) env('ATLAS_FABLE_DELTA_SERIES_ENABLED', true),
    ],

    // MAXK-08 — envelope EPHEMERAL ceiling by reversal rate (ACOS Max LOTE 8 F2).
    // Reads ASI-11 lineage rollback receipts and monotonic-DOWN-tightens the
    // stored autonomy envelope IN MEMORY. Never writes to the envelope const.
    // Widening is operator-only (MAXK-07 amendment). DEFAULT-OFF until the
    // lineage ledger accumulates real reversals; otherwise the denominator is
    // empty and tightening would be dishonest.
    'maxk08' => [
        'ephemeral_ceiling_enabled' => (bool) env('ATLAS_MAXK08_EPHEMERAL_CEILING_ENABLED', false),
    ],

    'cognition' => [
        // L3-11: agenda diária do mint de pipeline receipts (sobe a dimensão mais fraca do
        // ACOS com evidência resolved, mirando os subsistemas partial). Default ON; reversível.
        'mint_pipeline_receipts_enabled' => (bool) env('ATLAS_COGNITION_MINT_PIPELINE_RECEIPTS_ENABLED', true),
        'remint_touched_enabled' => (bool) env('ATLAS_COGNITION_REMINT_TOUCHED_ENABLED', false),
        'remint_touched_queue_disk' => env('ATLAS_COGNITION_REMINT_TOUCHED_QUEUE_DISK', 'local'),
        'remint_touched_queue_path' => env('ATLAS_COGNITION_REMINT_TOUCHED_QUEUE_PATH', 'atlas/cognition/remint-touched-queue.jsonl'),

        // L6-9: gate honesto para o claim "ACOS 10/10 real". Ele não cunha
        // receipts nem backfilla tempo; só permite completion quando o scorecard
        // resolved-evidence e a delta-series append-only sustentam >=30 dias.
        // SUB-01 · Verified snapshot/backup of memory substrate (tables + series JSONLs).
        // Destination lives outside the live Laravel tree (atlas.storage_path).
        'substrate_snapshot' => [
            'enabled' => (bool) env('ATLAS_COGNITION_SUBSTRATE_SNAPSHOT_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_COGNITION_SUBSTRATE_SNAPSHOT_SCHEDULE_ENABLED', true),
            'schedule_day' => max(0, min(6, (int) env('ATLAS_COGNITION_SUBSTRATE_SNAPSHOT_SCHEDULE_DAY', 0))),
            'schedule_time' => (string) env('ATLAS_COGNITION_SUBSTRATE_SNAPSHOT_SCHEDULE_TIME', '04:30'),
            'destination_root' => (string) env(
                'ATLAS_COGNITION_SUBSTRATE_SNAPSHOT_ROOT',
                rtrim((string) env('ATLAS_STORAGE_PATH', '/var/atlas/storage'), '/').'/substrate-snapshots',
            ),
            'tables' => [
                'atlas_memory_entries',
                'atlas_memory_entry_usages',
                'atlas_memory_entry_relations',
                'atlas_verbatim_memories',
                'atlas_decision_receipts',
                'atlas_memory_quality_snapshots',
                'atlas_memory_provider_projection_audits',
            ],
            'series_jsonls' => [
                storage_path('app/atlas/evidence/acos-delta-series.jsonl'),
                'live_outcomes:',
                storage_path('atlas/scheduler/heartbeat.jsonl'),
            ],
        ],

        // ELEV-17 · recurring restore drill. The target is a disposable DB only:
        // the guard refuses the canonical local Postgres port used by live Atlas.
        'substrate_restore_drill' => [
            'schedule_enabled' => (bool) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_SCHEDULE_ENABLED', true),
            'schedule_day' => max(1, min(28, (int) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_SCHEDULE_DAY', 1))),
            'schedule_time' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_SCHEDULE_TIME', '05:10'),
            'receipt_path' => (string) env(
                'ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_RECEIPT_PATH',
                storage_path('app/atlas/evidence/substrate-restore-drills.jsonl'),
            ),
            'max_success_age_days' => max(1, (int) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_MAX_SUCCESS_AGE_DAYS', 45)),
            'canonical' => [
                'host' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_CANONICAL_HOST', env('DB_HOST', '127.0.0.1')),
                'port' => (int) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_CANONICAL_PORT', 5433),
                'database' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_CANONICAL_DATABASE', env('DB_DATABASE', 'atlas')),
            ],
            'target' => [
                'host' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_TARGET_HOST', env('DB_HOST', '127.0.0.1')),
                'port' => (int) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_TARGET_PORT', 55433),
                'database' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_TARGET_DATABASE', 'atlas_restore_drill'),
                'username' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_TARGET_USERNAME', env('DB_USERNAME', 'atlas')),
                'password' => (string) env('ATLAS_COGNITION_SUBSTRATE_RESTORE_DRILL_TARGET_PASSWORD', env('DB_PASSWORD', '')),
            ],
        ],

        'acos_long_horizon_gate' => [
            'enabled' => (bool) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_ENABLED', true),
            'schedule_enabled' => (bool) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SCHEDULE_ENABLED', true),
            'schedule_time' => (string) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SCHEDULE_TIME', '06:55'),
            'min_days' => max(1, (int) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_DAYS', 30)),
            'min_overall' => max(0.0, min(10.0, (float) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_OVERALL', 9.5))),
            'min_pipeline' => max(0.0, min(10.0, (float) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MIN_PIPELINE', 9.5))),
            // EVI-07: early-warning margin above floors (read-only visibility; does not alter blockers).
            'warning_margin' => 0.15,
            // Freshness bound (calendar days): the latest delta-series day must be
            // within this window of "today" or the gate rejects it as stale. Any
            // future-dated row is always rejected. Mechanical does_not_backfill_time.
            'max_latest_stale_days' => max(0, (int) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MAX_LATEST_STALE_DAYS', 2)),
            // EVI-05: max calendar-day gap between consecutive sampled dates inside
            // the certification window (last min_days ending at latest_date). Default
            // 1 enforces full contiguity — any missing day blocks certification.
            'max_gap_days' => max(1, (int) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_MAX_GAP_DAYS', 1)),
            'series_path' => (string) env('ATLAS_COGNITION_ACOS_LONG_HORIZON_GATE_SERIES_PATH', storage_path('app/atlas/evidence/acos-delta-series.jsonl')),
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
        // Obra 7 / CMP-01: post-compaction marker → optional continuity inject (fail-open).
        'post_compaction_hooks' => [
            'enabled' => (bool) env('ATLAS_LONG_HORIZON_POST_COMPACTION_HOOKS_ENABLED', true),
            'continuity_inject' => (bool) env('ATLAS_LONG_HORIZON_POST_COMPACTION_CONTINUITY_INJECT', true),
            'marker_receipt_path' => (string) env(
                'ATLAS_LONG_HORIZON_POST_COMPACTION_MARKER_PATH',
                storage_path('app/atlas/evidence/post-compaction-markers.jsonl'),
            ),
        ],

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
        // O-1 single-feeder bridge: Forge work-packet completions may feed
        // the central compounding loop THROUGH the conductor
        // (recordExternalEngineeringOutcome — same substance gates as live
        // runs; the conductor stays the only recordExecution caller).
        // OUTC-01(c): default ON for real Forge executions; simulation stays quarantined.
        'forge_compounding_bridge_enabled' => (bool) env('ATLAS_PATAMAR4_FORGE_COMPOUNDING_BRIDGE_ENABLED', true),
        'scheduler_heartbeat_enabled' => (bool) env('ATLAS_PATAMAR4_SCHEDULER_HEARTBEAT_ENABLED', true),
        'scheduler_ensure_launchd_enabled' => (bool) env('ATLAS_PATAMAR4_SCHEDULER_ENSURE_LAUNCHD_ENABLED', true),
        'reconciliation_enabled' => (bool) env('ATLAS_PATAMAR4_RECONCILIATION_ENABLED', true),
        'reconciliation_cadence' => env('ATLAS_PATAMAR4_RECONCILIATION_CADENCE', 'fifteen'),
        'nightly_counterfactuals_enabled' => (bool) env('ATLAS_PATAMAR4_NIGHTLY_COUNTERFACTUALS_ENABLED', true),
        'adml_sweep_enabled' => (bool) env('ATLAS_PATAMAR4_ADML_SWEEP_ENABLED', true),
        // L5-6: ADML cost×outcome routing. This is not a parallel router:
        // activation still goes through the existing Atlas Decide routing
        // table + operator receipt, and gateway fallback remains intact.
        // Gates AtlasDecideMetaLearningService::autoActivateFromLiveEvidence() —
        // the auto-activation arc of the live evidence loop. OFF by default:
        // flipping to true is an operator decision; every activation still writes
        // the standard audit receipt and consult keeps its kernel/admission gates.
        'adml_auto_activation_enabled' => (bool) env('ATLAS_PATAMAR4_ADML_AUTO_ACTIVATION_ENABLED', false),

        'adml_cost_outcome' => [
            'enabled' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED', false),
            'min_evidence' => max(1, (int) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_EVIDENCE', 3)),
            'min_certification_rate' => max(0.0, min(1.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_CERTIFICATION_RATE', 0.8))),
            'min_score' => max(0.0, min(100.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_SCORE', 80.0))),
            'max_score_drop' => max(0.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MAX_SCORE_DROP', 3.0)),
            'require_measured_cost' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_REQUIRE_MEASURED_COST', true),
            'min_cost_samples' => max(1, (int) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MIN_COST_SAMPLES', 1)),
            'multi_objective' => [
                'enabled' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MULTI_OBJECTIVE_ENABLED', false),
                'risk_class' => (string) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_MULTI_OBJECTIVE_RISK_CLASS', 'default'),
                'weights' => [
                    'success' => max(0.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_WEIGHT_SUCCESS', 0.0)),
                    'cost' => max(0.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_WEIGHT_COST', 1.0)),
                    'latency' => max(0.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_WEIGHT_LATENCY', 0.0)),
                ],
            ],
            'cascade' => [
                'enabled' => (bool) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_CASCADE_ENABLED', false),
                'lower_bound_floor' => max(0.0, min(1.0, (float) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_CASCADE_LOWER_BOUND_FLOOR', 0.8))),
                'daily_escalation_cap' => max(0, (int) env('ATLAS_PATAMAR4_ADML_COST_OUTCOME_CASCADE_DAILY_CAP', 1)),
            ],
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
        'scope_lease_reaper_enabled' => (bool) env('ATLAS_FORGE_SCOPE_LEASE_REAPER_ENABLED', true),
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
    /*
    | FULL-PASS HYGIENE (operate-path honesty)
    | -------------------------------------------------------------------------
    | `loop` config lives in config/atlas_loop_legacy.php (legacy ACDE surface).
    | Daily operate path is atlas:brain:* / atlas:task:* (Autônomos live).
    | Keep-list AtlasLoop* may still read atlas.loop.*; never re-enable atlas:loop:* CLI.
    */
    'loop' => require __DIR__.'/atlas_loop_legacy.php',

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

        // MAXI-04 — semantic arm of the immune input classifier. Default OFF: with
        // the flag off, AtlasImmuneHybridInputClassifier::classifyHybrid() returns
        // the base classifier output verbatim plus an inert `hybrid_arm` block —
        // byte-identical to callers that read the base fields (proved by
        // AtlasImmuneHybridInputClassifierTest::switch_off_is_byte_identical).
        'immune_classifier' => [
            'semantic_arm_enabled' => (bool) env('ATLAS_AAEOS_IMMUNE_CLASSIFIER_SEMANTIC_ARM_ENABLED', false),
        ],
        // MAXI-05 — learned poison signatures (observe default; enforce blocks by ref).
        'immune_signature' => [
            'mode' => env('ATLAS_IMMUNE_SIGNATURE_MODE', 'observe'),
            'decay_days' => (int) env('ATLAS_IMMUNE_SIGNATURE_DECAY_DAYS', 90),
        ],
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

        // AP-815 Tier-1 fusion (REPORT-ONLY): when ON, build() runs the `callgraph`
        // op over the workspace source and reports method->method CALL-edge YIELD stats
        // in the receipt WITHOUT merging them into the persisted graph. DEFAULT OFF
        // (byte-identical when off). A preview so the operator can weigh the live-merge
        // perf cost against real yield before the separate live-merge slice is built.
        'call_edges' => (bool) env('ATLAS_CODE_GRAPH_CALL_EDGES', false),
        // Which extractor resolves the call edges: 'generic' (default; heuristic short-name,
        // INFERRED 0.7) or 'typed' (receiver-type-aware, EXTRACTED 1.0 — more precise for
        // PHP: $this/self/static/direct-type calls resolve certainly). Only one runs.
        'call_edges_mode' => (string) env('ATLAS_CODE_GRAPH_CALL_EDGES_MODE', 'generic'),
        // Upper bound on source files read per build when call_edges is ON (perf ceiling;
        // the perf-serious path must use the incremental reindex, not this full re-read).
        'call_edges_max_files' => (int) env('ATLAS_CODE_GRAPH_CALL_EDGES_MAX_FILES', 5000),
        // When ON, the resolved method->method call edges are MERGED into the persisted
        // world model (graph-mutating), not just reported. DEFAULT OFF. Enable only after
        // reviewing the call_edges yield stats — edges are INFERRED (0.7), shape-identical
        // to symbol edges, and subject to the same max_edges cap.
        'call_edges_merge' => (bool) env('ATLAS_CODE_GRAPH_CALL_EDGES_MERGE', false),

        // AP-815 P-7: framework-aware edges (route->controller, DI bindings) read from the
        // LIVE router/container via reflection — runtime wiring NO source parse can recover
        // (edges are EXTRACTED, shape-identical to symbol edges). The resolver is query-free
        // (never issues a DB query). Same report/merge split as call_edges, both DEFAULT OFF.
        'framework_edges' => (bool) env('ATLAS_CODE_GRAPH_FRAMEWORK_EDGES', false),
        'framework_edges_merge' => (bool) env('ATLAS_CODE_GRAPH_FRAMEWORK_EDGES_MERGE', false),
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
        // MAXD-04: shadow-only Personalized PageRank dual-read against BFS.
        // Default-OFF: records a JSONL ledger, never reorders live answers.
        'query_ppr_shadow_enabled' => (bool) env('ATLAS_AURG_QUERY_PPR_SHADOW_ENABLED', false),
        'query_ppr_shadow_latency_budget_ms' => (int) env('ATLAS_AURG_QUERY_PPR_SHADOW_LATENCY_BUDGET_MS', 2000),
        'query_ppr_shadow_ledger_path' => (string) env(
            'ATLAS_AURG_QUERY_PPR_SHADOW_LEDGER_PATH',
            storage_path(AtlasAurgPprShadowDualReadLedger::RELATIVE_PATH),
        ),
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
        // Obra 2 / OB-01: attach AtlasContextRuntime::compose as sidecar on packFor (fail-open).
        'include_runtime_compose' => (bool) env('ATLAS_AOBG_INCLUDE_RUNTIME_COMPOSE', false),
        // L3-6: rerank semântico da seção de memória do context pack via o engine local
        // real (embeddings sobre os itens recuperados). Default OFF; fail-open sem venv.
        'semantic_retrieval' => (bool) env('ATLAS_AOBG_SEMANTIC_RETRIEVAL', false),
        // MAXA-09: late-interaction local rerank over the final context-pack memory
        // candidate window. Default OFF until golden/live dual-read proves precision@5
        // lift and p95 latency within the declared window.
        'late_interaction_rerank' => (bool) env('ATLAS_AOBG_LATE_INTERACTION_RERANK', false),
        'late_interaction_candidate_window' => max(1, (int) env('ATLAS_AOBG_LATE_INTERACTION_CANDIDATE_WINDOW', 20)),
        'late_interaction_top_k' => max(1, (int) env('ATLAS_AOBG_LATE_INTERACTION_TOP_K', 5)),
        // MAXB-06: local cross-encoder rerank stage over the existing L3-6 seam.
        // Default OFF until golden v2 proves precision@3 lift and latency window.
        'cross_encoder_rerank' => (bool) env('ATLAS_AOBG_CROSS_ENCODER_RERANK', false),
        'cross_encoder_candidate_window' => max(1, (int) env('ATLAS_AOBG_CROSS_ENCODER_CANDIDATE_WINDOW', 30)),
        'cross_encoder_top_k' => max(1, (int) env('ATLAS_AOBG_CROSS_ENCODER_TOP_K', 3)),
        'cross_encoder_timeout_seconds' => max(1, (int) env('ATLAS_AOBG_CROSS_ENCODER_TIMEOUT_SECONDS', 3)),
        // MAXC-01: facet decomposition determinística no packFor (TaskFacetExtractor
        // routes typed sub-queries per source; passes extra são peek `record_usage=false`).
        // Default-OFF: pacote é byte-idêntico enquanto flag desligada; ligar só depois de
        // A/B provado no golden v2 (ELEV-01).
        'facet_retrieval' => (bool) env('ATLAS_AOBG_FACET_RETRIEVAL', false),
        // RAGX-08 extension: claim/span/content-version refs over the already
        // delivered pack. Default-OFF; no new retrieval pass, DB write, or LLM.
        'span_level_retrieval' => (bool) env('ATLAS_AOBG_SPAN_LEVEL_RETRIEVAL', false),
        // Fase 4 RAGX chain mechanisms. All default-OFF/shadow: these flags
        // expose wiring and ledgers only, never a live promotion or fake A/B green.
        'ragx_late_chunk_index' => (bool) env('ATLAS_AOBG_RAGX_LATE_CHUNK_INDEX', false),
        'ragx_late_chunk_maxa04_promoted' => (bool) env('ATLAS_AOBG_RAGX_LATE_CHUNK_MAXA04_PROMOTED', false),
        'ragx_adaptive_k' => (bool) env('ATLAS_AOBG_RAGX_ADAPTIVE_K', false),
        'ragx_sparse_fallback' => (bool) env('ATLAS_AOBG_RAGX_SPARSE_FALLBACK', false),
        'ragx_ab_registrar' => (bool) env('ATLAS_AOBG_RAGX_AB_REGISTRAR', false),
        'ragx_ab_ledger_path' => (string) env(
            'ATLAS_AOBG_RAGX_AB_LEDGER_PATH',
            storage_path('app/atlas/evidence/ragx-ab-registrations.jsonl'),
        ),
        'ragx_louvain_chunks' => (bool) env('ATLAS_AOBG_RAGX_LOUVAIN_CHUNKS', false),
        'ragx_maxa06_fase2_backfilled' => (bool) env('ATLAS_AOBG_RAGX_MAXA06_FASE2_BACKFILLED', false),
        'ragx_raptor_lite' => (bool) env('ATLAS_AOBG_RAGX_RAPTOR_LITE', false),
        // ESP-12: five-layer epistemic evidence bundle (must_carry,
        // novelty_pool, operator policy, CONTRAEVIDENCIA, claim citations).
        // Default-OFF; composes only provider-safe data already in packFor.
        'epistemic_evidence_bundle' => (bool) env('ATLAS_AOBG_EPISTEMIC_EVIDENCE_BUNDLE', false),
        // MAXE-07: source budget multipliers from measured expected value
        // (used_ratio * post_execution_utility by source bucket). Default-OFF;
        // v1 fixed-step source policy remains authoritative until enough measured
        // COM feedback exists and the operator flips this mode.
        'source_selection_ev_weighted' => (bool) env('ATLAS_AOBG_SOURCE_SELECTION_EV_WEIGHTED', false),
        // Deterministic cross-source Reciprocal Rank Fusion receipt. It only
        // reorders already provider-safe refs and invokes no provider.
        // Mode ladder: offline → shadow → canary → default (see AtlasIntelligenceRolloutMode).
        'fusion_enabled' => (bool) env('ATLAS_AOBG_FUSION_ENABLED', false),
        'fusion_mode' => (string) env('ATLAS_AOBG_FUSION_MODE', 'offline'),
        'fusion_canary_percent' => max(0, min(100, (int) env('ATLAS_AOBG_FUSION_CANARY_PERCENT', 0))),
        'fusion_limit' => max(1, (int) env('ATLAS_AOBG_FUSION_LIMIT', 12)),
        'fusion_rrf_k' => max(1, (int) env('ATLAS_AOBG_FUSION_RRF_K', 60)),
        // Total char budget for the assembled pack (a text brief, ~6000 chars).
        'budget_chars' => (int) env('ATLAS_AOBG_BUDGET_CHARS', 6000),
        // Per-source sub-budgets (the code-graph sub-budget is converted to a
        // token budget at ~4 chars/token for CodeGraphContextRetriever).
        'code_budget_chars' => (int) env('ATLAS_AOBG_CODE_BUDGET_CHARS', 2500),
        'memory_budget_chars' => (int) env('ATLAS_AOBG_MEMORY_BUDGET_CHARS', 2000),
        'pack_cache' => [
            'enabled' => (bool) env('ATLAS_AOBG_PACK_CACHE_ENABLED', true),
            'ttl_seconds' => (int) env('ATLAS_AOBG_PACK_CACHE_TTL_SECONDS', 300),
        ],
        // MAXM-07 — soft MCP call cadence by opaque client_id. Fail-open:
        // accounting failure annotates quota as unavailable and never blocks reads.
        'mcp_quota' => [
            'calls_per_window' => max(1, (int) env('ATLAS_AOBG_MCP_QUOTA_CALLS_PER_WINDOW', 120)),
            'window_seconds' => max(1, (int) env('ATLAS_AOBG_MCP_QUOTA_WINDOW_SECONDS', 60)),
        ],
        // Obra 7 / OPT-05: E-3 symbol budget alias (chars→~tokens at packFor; zero provider spend).
        'e3_symbol_budget_chars' => (int) env('ATLAS_AOBG_E3_SYMBOL_BUDGET_CHARS', (int) env('ATLAS_AOBG_CODE_BUDGET_CHARS', 2500)),
        // Obra 7 / OB-03: progressive disclosure manifest (Absorcao 4 phase 1).
        'progressive_disclosure_enabled' => (bool) env('ATLAS_AOBG_PROGRESSIVE_DISCLOSURE', true),

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
            // Obra 5 / EV-01: require evidence_refs on governed write-back (derive from files when absent).
            'require_evidence_refs' => (bool) env('ATLAS_AOBG_WB_REQUIRE_EVIDENCE_REFS', true),
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
        // ACDE F5+F6 — atom-request-identity guard on resume reuse. The resume path skips a DONE atom by id
        // with NO identity check; if the live plan's step changed, the stale certified result is silently
        // reused. When ON, a DONE atom is reused ONLY when its persisted request-identity (atom_request_hash,
        // stored in the node result json — NO new table, the atom catalog IS atlas_obra_nodes) matches the
        // live step's request; a changed step (or a node certified before the guard existed) re-runs. Guards
        // the STEP REQUEST identity, NOT the frozen verifier. Default OFF => reuse unconditionally => the
        // result json and resume behaviour are byte-identical.
        'atom_identity_resume_guard' => (bool) env('ATLAS_OBRA_ATOM_IDENTITY_RESUME_GUARD', false),
        // ACDE F7 — reorder independent obra steps by historical first-pass certified rate (do the
        // historically-easiest-first so the obra banks certified progress before a hard step can halt it).
        // DAG-safe topological reorder; empty prior == seq order (no-op until the prior fills). Default OFF =>
        // the proven seq walk is untouched => byte-identical.
        'first_pass_reorder_enabled' => (bool) env('ATLAS_OBRA_FIRST_PASS_REORDER_ENABLED', false),
        'first_pass_reorder_window_hours' => (int) env('ATLAS_OBRA_FIRST_PASS_REORDER_WINDOW_HOURS', 720),
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
    // Maestro adaptive routing — the worker-behavior learning circuit. The write
    // side (outcome bridge → durable behavior ledger) is ALWAYS on (it is just a
    // fact record); the READ side (claim-order demotion of families a worker keeps
    // giving back, plus the maestro:behaviors/route CLI verbs) is gated here.
    // Operator switch — OFF by default.
    'maestro' => [
        // Budget gate. AtlasMaestroBudgetGate reads BOTH of these and neither
        // path existed, so loadBudgets() always returned [] and the gate was a
        // permanent ALLOW: an operator who set ATLAS_MAESTRO_BUDGET_GATE_ENFORCE
        // =true flipped $enforce but had no budget to enforce, and believed
        // provider spend was being refused past the ceiling.
        //
        // Defaults keep today's behaviour exactly: enforce OFF, and a budget of
        // 0 is filtered out by loadBudgets()'s own `>= 0` map plus the gate's
        // "only bites above 0" rule — so nothing starts blocking by declaring
        // this. The operator opts in per ceiling.
        'cost' => [
            'budget_gate_enforce' => (bool) env('ATLAS_MAESTRO_BUDGET_GATE_ENFORCE', false),
            'budgets' => array_filter([
                'per_cycle' => (int) env('ATLAS_MAESTRO_BUDGET_PER_CYCLE_CENTS', 0),
                'per_provider_per_day' => (int) env('ATLAS_MAESTRO_BUDGET_PER_PROVIDER_PER_DAY_CENTS', 0),
            ], static fn (int $cents): bool => $cents > 0),
        ],

        'adaptive' => [
            'behavior_ledger_enabled' => (bool) env('ATLAS_MAESTRO_BEHAVIOR_LEDGER_ENABLED', false),
            'router_enabled' => (bool) env('ATLAS_MAESTRO_ADAPTIVE_ROUTER_ENABLED', false),
            'reshape_enabled' => (bool) env('ATLAS_MAESTRO_ADAPTIVE_RESHAPE_ENABLED', false),
            'router_min_success' => (int) env('ATLAS_MAESTRO_ROUTER_MIN_SUCCESS', 3),
            'max_active_claims' => (int) env('ATLAS_MAESTRO_MAX_ACTIVE_CLAIMS', 3),
            // Claim-order demotion floors: only demote with real evidence volume.
            'demotion_min_events' => (int) env('ATLAS_MAESTRO_DEMOTION_MIN_EVENTS', 3),
            'demotion_give_back_rate' => (float) env('ATLAS_MAESTRO_DEMOTION_GIVE_BACK_RATE', 0.5),
        ],
    ],

    'self_construction' => [
        // Learning-transfer admission ledger (outcome → lesson JSONL). Pinned to a
        // temp path in phpunit.xml so tests that exercise the report → learning
        // bridge never leak fixture lessons (pkt-A / evidence://) into the real
        // ledger a future reader would trust.
        'learning_transfer_admission_ledger_path' => env('ATLAS_LEARNING_TRANSFER_ADMISSION_LEDGER_PATH'),
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
