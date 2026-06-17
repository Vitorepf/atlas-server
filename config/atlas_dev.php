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
| Atlas Dev efficient is now the **canonical** path. Defaults are ON for
| `enabled` and `plan_enabled` in every environment; `run_enabled` and
| `desktop_enabled` remain OFF until provider invocation / desktop UX are
| explicitly opted in. `default_path` is the canonical CLI default. To fall
| back to the legacy pipeline, set `ATLAS_DEV_DEFAULT_PATH=legacy` or pass
| the `--legacy` flag explicitly.
|
| Canon: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
*/

return [
    'efficient' => [
        'enabled' => (bool) env('ATLAS_DEV_EFFICIENT_ENABLED', true),

        'plan_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_PLAN_ENABLED', true),

        'run_enabled' => (bool) env('ATLAS_DEV_EFFICIENT_RUN_ENABLED', false),

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
        'e1' => ['mode' => env('ATLAS_DEV_ELEVATION_E1_MODE', 'advisory')],

        // E2: Definition of Done + Semantic Acceptance Criteria (M1 milestone).
        'e2' => ['mode' => env('ATLAS_DEV_ELEVATION_E2_MODE', 'advisory')],

        // E3: Mutation Testing Gate (M3 milestone).
        'e3' => ['mode' => env('ATLAS_DEV_ELEVATION_E3_MODE', 'advisory')],

        // E4: Differential Testing / Shadow-Diff (M5 milestone).
        'e4' => ['mode' => env('ATLAS_DEV_ELEVATION_E4_MODE', 'advisory')],

        // E5: Pre-Patch Regression Baseline + Caller-Test Selection (M4 milestone).
        'e5' => ['mode' => env('ATLAS_DEV_ELEVATION_E5_MODE', 'advisory')],

        // E6: Spec-Driven Constitution Gate (M6 milestone).
        'e6' => ['mode' => env('ATLAS_DEV_ELEVATION_E6_MODE', 'advisory')],
    ],
];
