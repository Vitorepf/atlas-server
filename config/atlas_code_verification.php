<?php

declare(strict_types=1);

/**
 * Atlas Code · Verification Command Runner governance.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Defines which `verification_commands` Atlas is allowed to run on behalf
 * of the operator AFTER an observed session has imported its result. The
 * runner is governed by:
 *   - allowed_command_patterns (regex allowlist; defense-in-depth)
 *   - mode defaults to `dry_run` (NO execution) until the operator opts in
 *     and the policy explicitly authorizes the label
 *   - execute path requires a signed Ed25519 receipt + operator override token
 *   - per-run timeout
 *
 * Tightening / loosening: change config without touching code.
 */

return [
    // Hard kill switch. When false, every call returns dry_run regardless
    // of operator override / signed receipt.
    'execute_enabled' => filter_var(
        env('ATLAS_VERIFICATION_EXECUTE_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    // Per-command timeout in seconds. Kills the subprocess.
    'timeout_seconds' => (int) env('ATLAS_VERIFICATION_TIMEOUT_SECONDS', 600),

    // Regex allowlist (one pattern per entry). Anchored implicitly via `^...$`.
    // We DO NOT use shell — args are array-passed. The patterns match the
    // joined command-line for human review/audit only.
    'allowed_command_patterns' => [
        '#^pnpm (test|lint|build|typecheck)( --filter \S+)?$#',
        '#^npm run (test|lint|build|typecheck)$#',
        '#^yarn (test|lint|build|typecheck)$#',
        '#^composer (test|test:unit|test:feature)$#',
        '#^/opt/homebrew/bin/php artisan test( --filter=[A-Za-z0-9_]+)?$#',
        '#^php artisan test( --filter=[A-Za-z0-9_]+)?$#',
        '#^cargo (test|check)$#',
        '#^go test( \./\.\.\.)?$#',
        '#^pytest( -q)?$#',
    ],

    // Evidence storage directory.
    'evidence_dir' => env('ATLAS_VERIFICATION_EVIDENCE_DIR', storage_path('app/atlas-code/verification-runs')),

    // Operator override token required for execute mode (any non-empty string
    // accepted in v1; future revisions sign the token with the same Ed25519
    // keypair that signs decisions).
    'operator_override_env' => 'ATLAS_VERIFICATION_OPERATOR_TOKEN',
];
