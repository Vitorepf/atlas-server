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
| Atlas Dev efficient is now the **canonical** path and the governed delivery
| spine is ON by default. `enabled`, `plan_enabled` and `run_enabled` default
| ON in every environment; `desktop_enabled` remains OFF until the desktop UX is
| explicitly opted in. Turning `run_enabled` on by default is safe because the
| spine is governed: scope (ScopeGuard), verification (VerificationGate),
| honesty (CompletionStateGate) and the operator consent gate (`--yes`) still
| gate every provider spend. An operator can still hard-block the run surface
| by setting `ATLAS_DEV_EFFICIENT_RUN_ENABLED=false`. `default_path` is the
| canonical CLI default. To fall back to the legacy pipeline, set
| `ATLAS_DEV_DEFAULT_PATH=legacy` or pass the `--legacy` flag explicitly.
|
| Canon: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
*/

return [
    'efficient' => [
        'enabled' => (bool) env('ATLAS_DEV_EFFICIENT_ENABLED', true),

        'plan_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_PLAN_ENABLED', true),

        // The canonical governed run spine is ON by default (M1). The guard
        // sites (AtlasCliDevEfficientHandler, RunController) keep reading this
        // flag as a safety/capability check, but no longer hard-block by
        // default — the honesty/scope/verification gates + the operator
        // consent gate (--yes) are what make "on by default" safe. Set
        // ATLAS_DEV_EFFICIENT_RUN_ENABLED=false to re-engage the hard block.
        'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', true),

        'desktop_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED', false),

        // Fast local patcher for tiny smoke fixtures. Keep ON by default for
        // cheap smoke coverage, but live proof commands can force it OFF so
        // provider evidence cannot be confused with deterministic execution.
        'deterministic_fast_path_enabled' => (bool) env('ATLAS_DEV_DETERMINISTIC_FAST_PATH_ENABLED', true),

        // L4-9: Atlas Dev single-file Hermes runs are already fully governed by
        // TaskContract + ScopeGuard + VerificationGate. Use ACP transport and a
        // one-turn cap for those narrow edits; broader multi-file Dev runs keep
        // the global Hermes max-turn policy.
        'hermes_execution_transport' => env('ATLAS_DEV_HERMES_EXECUTION_TRANSPORT', 'acp'),
        'hermes_single_file_max_turns' => (int) env('ATLAS_DEV_HERMES_SINGLE_FILE_MAX_TURNS', 1),

        // Canonical default path for CLI / API entrypoints. When `efficient`,
        // any Atlas Dev entrypoint that did not explicitly opt out routes
        // through the efficient pipeline (plan → token → run). Operators can
        // restore the legacy preflight path globally via env or per-call via
        // the CLI `--legacy` flag.
        'default_path' => env('ATLAS_DEV_DEFAULT_PATH', 'efficient'),

        // `process` keeps Desktop/API callers responsive: /run accepts the
        // operator-confirmed work, writes queued state, spawns an isolated CLI
        // worker, and returns immediately. Tests and CLI smoke paths can force
        // `inline`; `after_response` remains as a fallback for hosts that
        // cannot spawn local processes.
        'run_dispatch_mode' => env('ATLAS_DEV_RUN_DISPATCH_MODE', 'process'),
    ],

    'confirmation_token' => [
        'ttl_seconds' => (int) env('ATLAS_DEV_CONFIRMATION_TOKEN_TTL_SECONDS', 300),
        'plaintext_bytes' => (int) env('ATLAS_DEV_CONFIRMATION_TOKEN_BYTES', 32),
    ],

    'run_index' => [
        'list_default_limit' => (int) env('ATLAS_DEV_RUN_INDEX_LIST_LIMIT', 50),
        'list_max_limit' => (int) env('ATLAS_DEV_RUN_INDEX_LIST_MAX_LIMIT', 200),
    ],

    'run_worker' => [
        'stale_after_seconds' => (int) env('ATLAS_DEV_RUN_WORKER_STALE_AFTER_SECONDS', 900),
    ],

    'provider' => [
        // Inner provider-call ceiling for a single owner-runtime attempt. 120s
        // was too tight for real agentic edits on a large repo (every real
        // AP-790 cycle died on owner_runtime_provider_timeout); the AP-786
        // owner flow threads an explicit, larger value per run via
        // `atlas:dev:senior-loop:run --provider-timeout-seconds`.
        'timeout_seconds' => (int) env('ATLAS_DEV_PROVIDER_TIMEOUT_SECONDS', 300),
        // Set ATLAS_DEV_DEFAULT_PROVIDER=minimax_m27_cli to use MiniMax as
        // automatic fallback when Claude/Codex are exhausted.
        'default_provider' => env('ATLAS_DEV_DEFAULT_PROVIDER', 'claude_cli'),
    ],

    'receipts_path' => env(
        'ATLAS_DEV_RECEIPTS_PATH',
        function_exists('storage_path') && method_exists(app(), 'storagePath')
            ? storage_path('atlas-dev/receipts')
            : sys_get_temp_dir().'/atlas-dev/receipts',
    ),

    // F-01: under PHP-FPM the SSE endpoint cannot keep a worker pinned, so the
    // current StreamController is snapshot-replay-then-close and does not read
    // these knobs. They are reserved for a future async runtime (Octane / Reverb
    // / ReactPHP) where a single process can host many idle long-lived streams.
    'stream' => [
        'timeout_seconds' => (int) env('ATLAS_DEV_STREAM_TIMEOUT_SECONDS', 300),
        'keepalive_seconds' => (int) env('ATLAS_DEV_STREAM_KEEPALIVE_SECONDS', 15),
    ],

    // Mandatory RAG Gate — fail-closed for non-trivial engineering tasks.
    // Atlas Dev / Atlas Forge must never execute work without sufficient
    // context. Bypass is OFF by default and requires explicit auditable
    // opt-in via operator user constraint. Every bypass is persisted in the
    // gate receipt for audit.
    'mandatory_rag_gate' => [
        'bypass_enabled' => (bool) env('ATLAS_DEV_MANDATORY_RAG_GATE_BYPASS_ENABLED', false),
        // When set, the bypass is only accepted from these productSurface
        // identifiers (e.g. ['atlas_cli', 'atlas_app']). Empty list means
        // any surface can bypass when bypass_enabled is true.
        'allowed_surfaces' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_DEV_MANDATORY_RAG_GATE_BYPASS_SURFACES', ''))
        ))),
    ],

    // ----------------------------------------------------------------------
    // Atlas Dev Elevation v2 (E1-E6) config-flag convention.
    // ----------------------------------------------------------------------
    // Each elevation (E1-E6) is governed by a tri-state `mode`:
    //   off      => the elevation is a no-op (byte-identical to pre-mission).
    //   advisory => the elevation surfaces ONLY via an honesty flag, which the
    //               CompletionStateGate auto-downgrades PASSED -> needs_review
    //               (never STATUS_FAILED for the flag alone).
    //   hard     => the elevation blocks via a sanctioned channel
    //               (STATUS_FAILED gate or STATUS_ESCALATE critic).
    //
    // SAFE DEFAULT: `advisory` for landed code. Unknown/missing/invalid values
    // resolve to advisory without crashing so a misconfigured flag can never
    // silently disable an elevation nor accidentally hard-block the pipeline.
    // An elevation is promoted advisory -> hard WITHIN its own milestone once
    // its trip condition is validated; it never starts at hard.
    //
    // Verdicts surface ONLY through the two sanctioned channels. There is no
    // third channel and no silent green: the CompletionDecision ctor forbids
    // status=passed alongside an honesty flag, so an advisory flag can never
    // coexist with a green completion.
    //
    // Reading flags: App\Services\Ai\Programming\AtlasDev\Support\Elevations\
    //                ElevationConfig::fromConfig('<eN>').
    //
    // Canon: mission architecture.md (Atlas Dev Elevation v2, Fase 1).
    // ----------------------------------------------------------------------
    'elevations' => [
        // E1: Intent Probe + Semantic Critic (M2 milestone).
        //   - mode: tri-state off|advisory|hard governing the deterministic
        //     intent-falsification probe + the detectIntentFalsification
        //     critic detector.
        //   - M2 (m2-e1-hard): promoted advisory -> hard. The trip condition
        //     (an intent-missing diff on a green gate) was verified on a
        //     fixture pair (E1HardGateTest: trip -> failed + flag retained;
        //     clear -> no false-fail on a genuine-intent diff) BEFORE the
        //     config default flipped, per the VAL-M2-028 rollout guard. A
        //     hard E1 trip forces STATUS_FAILED (completion `failed`, NOT the
        //     advisory `needs_review`) while preserving the
        //     `intent_likely_not_addressed` honesty flag for auditability
        //     (VAL-M2-002/004). A genuine-intent diff does not trip
        //     (VAL-M2-003). Operators can opt back down to advisory/off via
        //     ATLAS_DEV_ELEVATION_E1_MODE.
        //   - llm_judge: OPTIONAL adversarial LLM-as-judge sub-layer (default
        //     OFF). When ON, a judge callable is resolved from the container
        //     binding 'atlas_dev.e1.intent_judge' and invoked by the critic's
        //     detectIntentFalsification() AFTER the deterministic probe. The
        //     judge is strictly doubt-additive: it can only add doubt or
        //     escalate (VAL-E1-011). APPROVE/DOWNGRADE outcomes are ignored
        //     — the deterministic probe's verdict is the immovable floor and
        //     a fake judge screaming APPROVE cannot clear intent_likely_not_addressed.
        'e1' => [
            'mode' => env('ATLAS_DEV_ELEVATION_E1_MODE', 'hard'),
            'llm_judge' => (bool) env('ATLAS_DEV_ELEVATION_E1_LLM_JUDGE', false),
        ],

        // E2: Definition of Done + Semantic Acceptance Criteria (M1 milestone).
        //   - M2 (m2-e2-hard): promoted advisory -> hard. The trip condition
        //     (an intent NOT backed by any behavioral AC with a real
        //     verification_ref on a green gate) was verified on a fixture
        //     pair (E2HardGateTest: trip -> failed + flag retained; clear ->
        //     no false-fail when a behavioral AC with a real verification_ref
        //     backs the intent) BEFORE the config default flipped, per the
        //     VAL-M2-028 rollout guard. A hard E2 trip forces STATUS_FAILED
        //     (completion `failed`, NOT the advisory `needs_review`) while
        //     preserving the `intent_not_tested` honesty flag for
        //     auditability (VAL-M2-006). A behavioral AC with a real
        //     verification_ref does not trip (VAL-M2-007). Operators can opt
        //     back down to advisory/off via ATLAS_DEV_ELEVATION_E2_MODE.
        'e2' => ['mode' => env('ATLAS_DEV_ELEVATION_E2_MODE', 'hard')],

        // E3: Mutation Testing Gate (M3 milestone).
        //   - mode: tri-state off|advisory|hard governing the mutation-score
        //     gate. The gate reads the REAL infection-reported MSI (parsed by
        //     MutationTestingAdapter from the infection summary JSON — never
        //     a self-declared score, VAL-E3-007) and routes the verdict:
        //       advisory => honesty flag mutation_score_below_threshold
        //                   (downgrade PASSED -> needs_review, never green);
        //       hard     => STATUS_FAILED gate channel (never just downgrades);
        //       off      => byte-identical to pre-E3 (infection not invoked).
        //   - threshold: MSI percent floor (default 60.0). A patch whose real
        //     reported MSI is below this trips the gate. Boundary inclusive
        //     (MSI == threshold passes, VAL-E3-004).
        //
        // DEFAULT is `off`: unlike E1/E2 (pure-PHP logic), E3 spawns a scoped
        // infection subprocess that requires the pcov coverage driver
        // (VAL-E3-011). Deployments without pcov installed must keep E3 off
        // or the gate fail-closes on every run. Operators opt into advisory/
        // hard explicitly via ATLAS_DEV_ELEVATION_E3_MODE once pcov is
        // verified present (mission init.sh asserts pcov loaded).
        //   - threshold: MSI percent floor (default 60.0). A patch whose real
        //     reported MSI is below this trips the gate. Boundary inclusive
        //     (MSI == threshold passes, VAL-E3-004).
        //
        // m3-e3 scrutiny Defect 3: the env value is read WITHOUT a (float)
        // cast here. A (float) cast silently turns a non-numeric env value
        // (e.g. ATLAS_DEV_ELEVATION_E3_THRESHOLD=banana) into 0.0, which
        // DISABLES the gate (MSI >= 0.0 is always true). The gate's
        // MutationScoreGate::fromConfig() reads the raw env directly and
        // validates it (invalid -> safe default 60.0, never 0.0); the config
        // value here is kept raw (string|null) so the gate can distinguish
        // 'banana' (invalid) from '0' (operator choice).
        'e3' => [
            'mode' => env('ATLAS_DEV_ELEVATION_E3_MODE', 'off'),
            'threshold' => env('ATLAS_DEV_ELEVATION_E3_THRESHOLD', 60.0),
        ],

        // E4: Differential Testing / Shadow-Diff (M5 milestone).
        //   - M2 (m2-e4-hard): promoted advisory -> hard. The trip condition
        //     (a pure-function change diverging on probed inputs NOT covered
        //     by unit tests, despite a green gate) was verified on a fixture
        //     pair (E4HardGateTest: trip -> failed + flag retained; clear ->
        //     no false-fail on a behavior-preserving pure refactor) BEFORE
        //     the config default flipped, per the VAL-M2-028 rollout guard.
        //     A hard E4 trip forces STATUS_FAILED (completion `failed`, NOT
        //     the advisory `needs_review`) while preserving the
        //     `shadow_diff_regression` honesty flag for auditability
        //     (VAL-M2-009). A behavior-preserving pure refactor (identical
        //     outputs across all probed inputs, diverged=false) does not trip
        //     (VAL-M2-010). Operators can opt back down to advisory/off via
        //     ATLAS_DEV_ELEVATION_E4_MODE.
        'e4' => ['mode' => env('ATLAS_DEV_ELEVATION_E4_MODE', 'hard')],

        // E5: Pre-Patch Regression Baseline + Caller-Test Selection (M4 milestone).
        //   - M2 (m2-e5-hard): promoted advisory -> hard. The trip condition
        //     (a scoped test that passed in the pre-patch baseline fails
        //     after the patch = a passed-before/fails-after regression) was
        //     verified on a fixture pair (E5HardGateTest: trip -> failed +
        //     flag retained + regressing test named; clear -> no false-fail
        //     on a pre-existing failure that is not a regression) BEFORE the
        //     config default flipped, per the VAL-M2-028 rollout guard. A
        //     hard E5 trip forces STATUS_FAILED (completion `failed`, NOT
        //     the advisory `needs_review`) while preserving the
        //     `regression_detected` honesty flag for auditability
        //     (VAL-M2-012). A pre-existing failure (failed-before +
        //     fails-after) is NOT classified as a regression, so E5 hard
        //     does not trip and does not false-fail (VAL-M2-013). Operators
        //     can opt back down to advisory/off via ATLAS_DEV_ELEVATION_E5_MODE.
        'e5' => ['mode' => env('ATLAS_DEV_ELEVATION_E5_MODE', 'hard')],

        // E6: Spec-Driven Constitution Gate (M6 milestone).
        'e6' => ['mode' => env('ATLAS_DEV_ELEVATION_E6_MODE', 'advisory')],
    ],
];
