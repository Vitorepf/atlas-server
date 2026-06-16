<?php

declare(strict_types=1);

/**
 * Atlas Code Provider Governance — configurable policy.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * This module governs HOW Atlas may invoke providers (Claude Code, Codex,
 * Gemini, etc.). It is NOT a hardcoded subscription-only kill switch — Atlas
 * still needs programmatic Claude for Rivals baselines, integration tests
 * and approved experiments BEFORE the Anthropic cutoff. The policy is
 * configurable so we can tighten it later via config/env, not refactor.
 *
 * Programmatic invocation categories (canonical labels):
 *   - rivals_baseline           · Rivals/Arena baseline runs vs Atlas Forge
 *   - provider_integration_test · CI/integration suite that talks to a real CLI
 *   - benchmark                 · Performance/quality benchmark runs
 *   - approved_experiment       · One-off experiment explicitly authorised
 *   - productive_headless       · Production-class headless agent loops
 *
 * `productive_headless` MUST be blocked by default. The other four are
 * allowed in `test_only` / `allowed_now` modes.
 *
 * Policy values:
 *   - allowed_now      · everything allowed (transient, used pre-cutoff)
 *   - test_only        · rivals + tests OK, productive_headless blocked
 *   - interactive_only · only `claude` interactive observed sessions
 *   - blocked          · full kill switch
 *
 * Override: when policy="blocked" but operator_override_required=true and the
 * label is included in `operator_override_labels`, callers must pass an
 * explicit `operator_override_token`. The service does NOT mint tokens; the
 * operator does this manually via env or admin endpoint (future).
 */

return [
    // Canonical policy state. Default keeps current workflows (Rivals + tests)
    // alive while productive_headless stays off. Move to 'interactive_only'
    // or 'blocked' before the Anthropic Agent SDK cutoff if needed.
    'claude_programmatic_policy' => env('ATLAS_CLAUDE_PROGRAMMATIC_POLICY', 'test_only'),

    // Per-category switches. Take precedence over the policy when the policy
    // is `allowed_now` (loosest). Under `test_only`, only categories with
    // allow_*=true here are honored.
    // DEPRECATED / DISABLED (operator decision): Rivals was a FAILED approach to evaluation and is OFF
    // indefinitely. Quality is NOT proven by repeated Rivals/Arena head-to-head baselines anymore; it is
    // proven PER DELIVERY — the operator evaluates the engineering, the workflow and the actual delivery
    // (diff + machine-resolved certification dossier) and compares THAT to Opus. Do NOT build/fix/rely on
    // Rivals. The RUNTIME off-switch is `ATLAS_ALLOW_RIVALS_PROGRAMMATIC=false` in .env (operator domain) —
    // already the operator's stance. The code DEFAULT is left `true` ONLY so the legacy Rivals test suite
    // does not go red (fixing those tests would be spending energy on a deprecated system). See
    // docs/acde-teto-closure.md ("Evaluation = per-delivery — Rivals + repeated head-to-head are DISABLED").
    'allow_rivals_programmatic' => filter_var(
        env('ATLAS_ALLOW_RIVALS_PROGRAMMATIC', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'allow_programmatic_tests' => filter_var(
        env('ATLAS_ALLOW_PROGRAMMATIC_TESTS', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'allow_productive_headless' => filter_var(
        env('ATLAS_ALLOW_PRODUCTIVE_HEADLESS', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'allow_api_payg' => filter_var(
        env('ATLAS_ALLOW_API_PAYG', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    // ISO date string (e.g. "2026-06-15"). When set, the policy hardens to
    // `interactive_only` automatically after this date — read-only signal
    // surfaced to UI; no automatic mutation of the policy value itself.
    'hard_block_after' => env('ATLAS_CLAUDE_HARD_BLOCK_AFTER', '2026-06-15'),

    // When the policy is restrictive, certain labels still go through if the
    // operator opts in with an explicit token (future admin endpoint).
    'operator_override_required' => filter_var(
        env('ATLAS_PROVIDER_OPERATOR_OVERRIDE_REQUIRED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'operator_override_labels' => ['productive_headless', 'approved_experiment'],

    // Display labels for UI — must match the canon list above.
    'allowed_labels' => [
        'rivals_baseline',
        'provider_integration_test',
        'benchmark',
        'approved_experiment',
        'productive_headless',
    ],

    // Providers registered for interactive observed sessions. Atlas opens
    // these for the operator to run locally. Atlas never executes the binary
    // itself for these flows — the operator runs `claude`/`codex`/`gemini`
    // after copying the prompt.
    'providers' => [
        [
            'id' => 'claude_code',
            'name' => 'Claude Code',
            'family' => 'anthropic',
            'invocation_mode' => 'interactive_observed',
            'binary_hint' => 'claude',
            'subscription_status' => 'allowed',
            'bootstrap_role' => 'implementation_lead',
            'notes' => 'Claude Code interativo observado. Para Rivals/tests/baseline, ver claude_programmatic_policy + allow_rivals_programmatic.',
        ],
        [
            'id' => 'codex_cli',
            'name' => 'Codex CLI',
            'family' => 'openai',
            'invocation_mode' => 'interactive_observed',
            'binary_hint' => 'codex',
            'subscription_status' => 'allowed',
            'bootstrap_role' => 'integration_owner',
            'notes' => 'Codex 5.5 interativo. Bootstrap como integrador/reviewer enquanto não houver evidence ledger suficiente.',
        ],
        [
            'id' => 'gemini_cli',
            'name' => 'Gemini CLI',
            'family' => 'google',
            'invocation_mode' => 'interactive_observed',
            'binary_hint' => 'gemini',
            'subscription_status' => 'allowed',
            'bootstrap_role' => 'context_scout',
            'notes' => 'Apoio: pesquisa/comparação interativa. Não é fonte de verdade.',
        ],
        [
            'id' => 'manual_external',
            'name' => 'Outro provider (manual import)',
            'family' => 'manual',
            'invocation_mode' => 'manual_import',
            'binary_hint' => null,
            'subscription_status' => 'allowed',
            'bootstrap_role' => 'challenger',
            'notes' => 'Operador rodou um provider fora do Atlas e está importando relatório+diff manualmente.',
        ],
    ],

    // Bootstrap role hints — only used until Provider Performance Ledger
    // has enough evidence to drive Dynamic Provider Role Assignment.
    'bootstrap_role_assignments' => [
        'implementation_lead' => 'claude_code',
        'integration_owner' => 'codex_cli',
        'context_scout' => 'gemini_cli',
        'critical_reviewer' => 'codex_cli',
        'challenger' => 'manual_external',
    ],

    'allowed_invocation_modes' => [
        'interactive_observed',
        'manual_import',
    ],
];
