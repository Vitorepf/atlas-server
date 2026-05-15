<?php

declare(strict_types=1);

/**
 * Atlas Code Provider Governance — Subscription-Only mode.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Operating principle: Atlas owns Obra, scope, packet, workspace, evidence,
 * review and acceptance. Providers (Claude Code, Codex, Gemini, future) are
 * substitutable executors. In subscription-only mode, headless paths are
 * BLOCKED — only interactive observed sessions are allowed.
 *
 * This config is read by `AtlasCodeProviderGovernanceService` and surfaced
 * via the `/atlas-code/providers/governance` endpoint. UI uses it to:
 *   - decide which providers to expose in the "Open Observed Provider" CTA
 *   - render the safety strip with explicit "headless: blocked" badges
 *   - block any code path that would call `claude -p` / API / SDK
 */

return [
    'subscription_only' => filter_var(
        env('ATLAS_CLAUDE_SUBSCRIPTION_ONLY', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    'mode_label' => 'subscription_only',

    // Hard prohibitions surfaced to UI as a "what is blocked" list. These
    // mirror the canon doc and must never be relaxed silently.
    'prohibitions' => [
        'claude_dash_p' => true,
        'claude_agent_sdk' => true,
        'anthropic_api_key_fallback' => true,
        'headless_worker' => true,
        'github_actions_claude' => true,
        'silent_fallback' => true,
        'multiuser_via_personal_subscription' => true,
    ],

    // Allowed invocation modes. UI only shows providers whose mode is in
    // this allowlist for the active operator.
    'allowed_invocation_modes' => [
        'interactive_observed',
        'manual_import',
    ],

    // Providers registered for interactive observed sessions. Each entry
    // describes how the operator launches the provider locally — Atlas
    // never executes the binary; the operator runs it after copying the
    // prompt and acknowledging the packet.
    'providers' => [
        [
            'id' => 'claude_code',
            'name' => 'Claude Code',
            'family' => 'anthropic',
            'invocation_mode' => 'interactive_observed',
            'binary_hint' => 'claude',
            'subscription_status' => 'allowed',
            'bootstrap_role' => 'implementation_lead',
            'notes' => 'Claude Code interativo observado. Operador roda `claude` no terminal aberto pelo Atlas. claude -p / Agent SDK proibidos.',
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
];
