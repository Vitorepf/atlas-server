<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Code Provider Governance · configurable policy read-model.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Schema: atlas.code.provider_governance.v2
 *
 * This service replaces the older "subscription_only=hardcoded_true" check
 * with a configurable policy so Atlas can keep using Claude programmatically
 * for Rivals baselines, integration tests and approved experiments BEFORE
 * the Anthropic cutoff, while keeping productive_headless off by default.
 *
 * Use `decideProgrammaticInvocation($label)` from any callsite that wants
 * to invoke Claude (or any provider) programmatically. The result is a
 * decision packet: allowed/blocked, reason, next_action. Never bypass.
 *
 * Categories handled (must match config allowed_labels):
 *   - rivals_baseline
 *   - provider_integration_test
 *   - benchmark
 *   - approved_experiment
 *   - productive_headless
 *
 * Policy values:
 *   - allowed_now       · everything permitted (transient)
 *   - test_only         · rivals/tests/benchmark allowed, productive blocked
 *   - interactive_only  · only interactive_observed sessions, no programmatic
 *   - blocked           · full kill switch
 */
final class AtlasCodeProviderGovernanceService
{
    public const SCHEMA_VERSION = 'atlas.code.provider_governance.v2';

    public const POLICIES = ['allowed_now', 'test_only', 'interactive_only', 'blocked'];

    public const CANONICAL_LABELS = [
        'rivals_baseline',
        'provider_integration_test',
        'benchmark',
        'approved_experiment',
        'productive_headless',
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $policy = $this->normalisePolicy((string) config('atlas_code_provider_governance.claude_programmatic_policy', 'test_only'));
        $allowRivals = (bool) config('atlas_code_provider_governance.allow_rivals_programmatic', true);
        $allowTests = (bool) config('atlas_code_provider_governance.allow_programmatic_tests', true);
        $allowProductive = (bool) config('atlas_code_provider_governance.allow_productive_headless', false);
        $allowApi = (bool) config('atlas_code_provider_governance.allow_api_payg', false);
        $hardBlockAfter = $this->normaliseDate((string) config('atlas_code_provider_governance.hard_block_after', ''));
        $hardBlockReached = $this->isPastDate($hardBlockAfter);
        $overrideRequired = (bool) config('atlas_code_provider_governance.operator_override_required', true);
        $overrideLabels = $this->stringList((array) config('atlas_code_provider_governance.operator_override_labels', []));
        $providers = (array) config('atlas_code_provider_governance.providers', []);
        $bootstrap = (array) config('atlas_code_provider_governance.bootstrap_role_assignments', []);
        $modes = $this->stringList((array) config('atlas_code_provider_governance.allowed_invocation_modes', []));
        $labels = $this->stringList((array) config('atlas_code_provider_governance.allowed_labels', self::CANONICAL_LABELS));

        // Derived booleans — what is actually permitted right now.
        $effectivePolicy = $hardBlockReached && $policy !== 'blocked' ? 'interactive_only' : $policy;
        $labelDecisions = [];
        foreach ($labels as $label) {
            $labelDecisions[$label] = $this->decideForLabel(
                $label,
                $effectivePolicy,
                $allowRivals,
                $allowTests,
                $allowProductive,
                $overrideRequired,
                in_array($label, $overrideLabels, true)
            );
        }

        // Aggregate flags for the UI safety strip.
        $anyProgrammaticAllowed = false;
        foreach ($labelDecisions as $decision) {
            if (($decision['allowed'] ?? false) === true) {
                $anyProgrammaticAllowed = true;
                break;
            }
        }
        $interactiveOnly = $effectivePolicy === 'interactive_only' || $effectivePolicy === 'blocked';
        $fullBlock = $effectivePolicy === 'blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claude_programmatic_policy' => $policy,
            'effective_policy' => $effectivePolicy,
            'hard_block_after' => $hardBlockAfter,
            'hard_block_after_reached' => $hardBlockReached,
            'allow_rivals_programmatic' => $allowRivals,
            'allow_programmatic_tests' => $allowTests,
            'allow_productive_headless' => $allowProductive,
            'allow_api_payg' => $allowApi,
            'operator_override_required' => $overrideRequired,
            'operator_override_labels' => $overrideLabels,
            'operator_presence_required' => true,
            'allowed_invocation_modes' => $modes,
            'allowed_labels' => $labels,
            'label_decisions' => $labelDecisions,
            'programmatic_invocation_allowed' => $anyProgrammaticAllowed,
            'productive_headless_allowed' => $labelDecisions['productive_headless']['allowed'] ?? false,
            'interactive_only' => $interactiveOnly,
            'full_block' => $fullBlock,
            'providers' => array_map(fn (array $p): array => $this->shapeProvider($p), array_values(array_filter($providers, 'is_array'))),
            'bootstrap_role_assignments' => array_map(static fn ($v): string => (string) $v, $bootstrap),
            'api_key_detected' => $this->detectApiKey(),
            'safety_notes' => [
                'productive_headless_blocked_by_default',
                'rivals_and_tests_permitted',
                'no_silent_fallback_to_api_payg',
                'human_acceptance_required_for_obra_completion',
                'workspace_path_required_for_execution',
                'all_programmatic_calls_must_carry_explicit_use_label',
            ],
        ];
    }

    /**
     * Single authoritative decision for a programmatic invocation attempt.
     *
     * Callers must pass `$label` matching one of CANONICAL_LABELS. The
     * `$operatorOverrideToken` is currently a placeholder for a future
     * explicit override flow; when provided, labels in
     * `operator_override_labels` may be permitted under stricter policies
     * (still respecting `full_block`).
     *
     * @return array{allowed: bool, reason: string, next_action: string, label: string, policy: string}
     */
    public function decideProgrammaticInvocation(string $label, ?string $operatorOverrideToken = null): array
    {
        $snap = $this->snapshot();
        $decisions = (array) $snap['label_decisions'];
        $effective = (string) $snap['effective_policy'];

        if (! in_array($label, (array) $snap['allowed_labels'], true)) {
            return [
                'allowed' => false,
                'reason' => "label_not_recognized: '{$label}' não está em allowed_labels",
                'next_action' => 'Use uma label canônica: '.implode(', ', self::CANONICAL_LABELS),
                'label' => $label,
                'policy' => $effective,
            ];
        }

        $decision = $decisions[$label] ?? null;
        if (! is_array($decision)) {
            return [
                'allowed' => false,
                'reason' => 'label_decision_missing',
                'next_action' => 'Verifique config/atlas_code_provider_governance.php',
                'label' => $label,
                'policy' => $effective,
            ];
        }

        // Operator override path: if the label requires override and the token
        // is present (we accept any non-empty token here; in v2 of this
        // service a signed token can be enforced), upgrade allowed=true.
        $needsOverride = (bool) ($decision['requires_operator_override'] ?? false);
        if ($needsOverride && ! ($decision['allowed'] ?? false)) {
            if ($effective === 'blocked') {
                return array_merge($decision, [
                    'reason' => 'policy_blocked: override não permitido em full_block',
                    'next_action' => 'Mude claude_programmatic_policy para sair de "blocked".',
                ]);
            }
            if ($operatorOverrideToken !== null && trim($operatorOverrideToken) !== '') {
                return [
                    'allowed' => true,
                    'reason' => 'operator_override_token_accepted',
                    'next_action' => 'Execução permitida com receipt humano.',
                    'label' => $label,
                    'policy' => $effective,
                    'requires_operator_override' => true,
                    'override_used' => true,
                ];
            }
        }

        return array_merge(
            [
                'label' => $label,
                'policy' => $effective,
            ],
            $decision
        );
    }

    public function allowedProviderIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $p): ?string => isset($p['id']) ? (string) $p['id'] : null,
            (array) config('atlas_code_provider_governance.providers', [])
        )));
    }

    public function findProvider(string $providerId): ?array
    {
        $needle = trim($providerId);
        if ($needle === '') {
            return null;
        }
        foreach ((array) config('atlas_code_provider_governance.providers', []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            if ((string) ($p['id'] ?? '') === $needle) {
                return $this->shapeProvider($p);
            }
        }

        return null;
    }

    /**
     * Decide a single label under a given effective policy.
     *
     * @return array<string, mixed>
     */
    private function decideForLabel(
        string $label,
        string $effectivePolicy,
        bool $allowRivals,
        bool $allowTests,
        bool $allowProductive,
        bool $overrideRequired,
        bool $labelOverrideEligible
    ): array {
        // Full-block kills everything.
        if ($effectivePolicy === 'blocked') {
            return [
                'allowed' => false,
                'reason' => 'policy_blocked',
                'next_action' => 'Mude ATLAS_CLAUDE_PROGRAMMATIC_POLICY para test_only ou allowed_now.',
                'requires_operator_override' => $overrideRequired && $labelOverrideEligible,
            ];
        }
        // Interactive-only blocks every programmatic label.
        if ($effectivePolicy === 'interactive_only') {
            return [
                'allowed' => false,
                'reason' => 'policy_interactive_only',
                'next_action' => 'Use Claude Code interativo observado a partir do Operating Room.',
                'requires_operator_override' => $overrideRequired && $labelOverrideEligible,
            ];
        }

        $policyAllows = $effectivePolicy === 'allowed_now' || $effectivePolicy === 'test_only';

        $allowedFromSwitch = match ($label) {
            'rivals_baseline' => $allowRivals,
            'provider_integration_test' => $allowTests,
            'benchmark' => $allowRivals || $allowTests,
            'approved_experiment' => $effectivePolicy === 'allowed_now',
            'productive_headless' => $allowProductive,
            default => false,
        };

        if (! $policyAllows || ! $allowedFromSwitch) {
            return [
                'allowed' => false,
                'reason' => $label === 'productive_headless'
                    ? 'productive_headless_blocked_by_default'
                    : ($policyAllows ? "switch_off:{$label}" : 'policy_blocked'),
                'next_action' => $label === 'productive_headless'
                    ? 'productive_headless requer allow_productive_headless=true E operator override.'
                    : 'Verifique config/atlas_code_provider_governance.php para a flag específica.',
                'requires_operator_override' => $overrideRequired && $labelOverrideEligible,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'permitted_by_policy_and_switch',
            'next_action' => 'Chamada programática permitida; sempre passe explicit_use_label.',
            'requires_operator_override' => $overrideRequired && $labelOverrideEligible,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function shapeProvider(array $p): array
    {
        return [
            'id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ($p['id'] ?? '')),
            'family' => (string) ($p['family'] ?? 'unknown'),
            'invocation_mode' => (string) ($p['invocation_mode'] ?? 'interactive_observed'),
            'binary_hint' => isset($p['binary_hint']) && $p['binary_hint'] !== '' ? (string) $p['binary_hint'] : null,
            'subscription_status' => (string) ($p['subscription_status'] ?? 'allowed'),
            'bootstrap_role' => isset($p['bootstrap_role']) ? (string) $p['bootstrap_role'] : null,
            'notes' => (string) ($p['notes'] ?? ''),
        ];
    }

    private function normalisePolicy(string $raw): string
    {
        $clean = strtolower(trim($raw));
        // Accept friendly aliases ("allowed_now_for_tests" → "test_only").
        $aliases = [
            'allowed_now_for_tests' => 'test_only',
        ];
        if (isset($aliases[$clean])) {
            $clean = $aliases[$clean];
        }
        if (! in_array($clean, self::POLICIES, true)) {
            return 'test_only';
        }
        return $clean;
    }

    private function normaliseDate(string $raw): ?string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }
        $ts = strtotime($clean);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    private function isPastDate(?string $isoDate): bool
    {
        if ($isoDate === null) {
            return false;
        }
        $ts = strtotime($isoDate);
        if ($ts === false) {
            return false;
        }
        return time() > $ts;
    }

    private function detectApiKey(): bool
    {
        foreach (['ANTHROPIC_API_KEY', 'CLAUDE_API_KEY', 'OPENAI_API_KEY'] as $key) {
            if (env($key) !== null && env($key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values($out);
    }
}
