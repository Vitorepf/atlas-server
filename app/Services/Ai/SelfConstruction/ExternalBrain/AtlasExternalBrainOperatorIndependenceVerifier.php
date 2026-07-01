<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier. Checks that a proposed self-construction plan has NO human,
 * operator, Claude/Codex, or external-provider dependency on the steady-state
 * critical path.
 *
 * A step is BLOCKING when ALL of the following are true:
 *   - on_critical_path = true
 *   - dependency_type is one of: human | operator | claude_codex | external_provider
 *   - NOT (is_bootstrap_only = true AND atlas_native_path_exists = true)
 *     (i.e. it is not a proven-native bootstrap seam)
 *
 * A step is OPTIONAL when EITHER:
 *   - is_bootstrap_only = true AND atlas_native_path_exists = true
 *     (external agents as accelerators are allowed; the Atlas-native path is complete)
 *   - dependency_context = exceptional_audit_or_policy_review AND
 *     dependency_type is human or operator
 *     (occasional human audit/policy review does not break 24/7 steady-state
 *     autonomy; only a human/operator decision required to CREATE, REPAIR or
 *     ROUTE an ordinary task on the steady-state path is blocking)
 *
 * dependency_context defaults to steady_state_human_dependency when omitted,
 * so every step is treated as ordinary steady-state work unless explicitly
 * marked exceptional.
 *
 * verdict: passed = true when blocking_dependencies is empty.
 *
 * Blocking dep types (in severity order): human → operator → claude_codex → external_provider.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainOperatorIndependenceVerifier
{
    public const SCHEMA = 'atlas.external_brain.operator_independence_verifier.v1';

    public const DEP_HUMAN             = 'human';
    public const DEP_OPERATOR          = 'operator';
    public const DEP_CLAUDE_CODEX      = 'claude_codex';
    public const DEP_EXTERNAL_PROVIDER = 'external_provider';
    public const DEP_ATLAS_NATIVE      = 'atlas_native';

    public const CONTEXT_STEADY_STATE = 'steady_state_human_dependency';

    public const CONTEXT_EXCEPTIONAL  = 'exceptional_audit_or_policy_review';

    private const BLOCKING_TYPES = [
        self::DEP_HUMAN,
        self::DEP_OPERATOR,
        self::DEP_CLAUDE_CODEX,
        self::DEP_EXTERNAL_PROVIDER,
    ];

    private const UNBLOCK_ACTIONS = [
        self::DEP_HUMAN             => 'replace_human_step_with_atlas_native_automation',
        self::DEP_OPERATOR          => 'replace_operator_approval_with_atlas_gate_or_evidence_check',
        self::DEP_CLAUDE_CODEX      => 'mark_as_bootstrap_only_and_prove_atlas_native_path',
        self::DEP_EXTERNAL_PROVIDER => 'mark_as_bootstrap_only_and_prove_atlas_native_path',
    ];

    /**
     * @param  array{
     *   plan_id?: string,
     *   steps?: list<array{
     *     step?: string,
     *     dependency_type?: string,
     *     on_critical_path?: bool,
     *     is_bootstrap_only?: bool,
     *     atlas_native_path_exists?: bool,
     *   }>,
     * }  $plan
     * @return array{schema:string, plan_id:string, passed:bool, blocking_dependencies:list<array>, optional_dependencies:list<array>, next_unblock_action:string|null}
     */
    public function verify(array $plan): array
    {
        $planId = trim((string) ($plan['plan_id'] ?? ''));
        $steps  = (array) ($plan['steps'] ?? []);

        $blocking = [];
        $optional = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepName    = trim((string) ($step['step']          ?? ''));
            $depType     = trim((string) ($step['dependency_type'] ?? self::DEP_ATLAS_NATIVE));
            $onPath      = (bool) ($step['on_critical_path']       ?? false);
            $bootstrapOnly  = (bool) ($step['is_bootstrap_only']      ?? false);
            $nativeExists   = (bool) ($step['atlas_native_path_exists'] ?? false);
            $dependencyContext = trim((string) ($step['dependency_context'] ?? self::CONTEXT_STEADY_STATE));

            if (! in_array($depType, self::BLOCKING_TYPES, true)) {
                continue; // atlas_native or unknown: not a concern
            }

            $isProvenBootstrap = $bootstrapOnly && $nativeExists;
            $isExceptionalReview = $dependencyContext === self::CONTEXT_EXCEPTIONAL
                && in_array($depType, [self::DEP_HUMAN, self::DEP_OPERATOR], true);

            if ($isProvenBootstrap) {
                $optional[] = [
                    'step'            => $stepName,
                    'dependency_type' => $depType,
                    'reason'          => 'bootstrap_only_with_proven_atlas_native_path',
                ];
                continue;
            }

            if ($isExceptionalReview) {
                $optional[] = [
                    'step'            => $stepName,
                    'dependency_type' => $depType,
                    'reason'          => 'exceptional_audit_or_policy_review_not_steady_state',
                ];
                continue;
            }

            if ($onPath) {
                $blocking[] = [
                    'step'            => $stepName,
                    'dependency_type' => $depType,
                    'on_critical_path' => true,
                    'unblock_action'  => self::UNBLOCK_ACTIONS[$depType] ?? 'investigate_and_remove_dependency',
                ];
            }
        }

        // Severity order: human > operator > claude_codex > external_provider, regardless of the
        // order steps were declared in — the most severe dependency always drives next_unblock_action.
        usort($blocking, static fn (array $a, array $b): int =>
            array_search($a['dependency_type'], self::BLOCKING_TYPES, true)
                <=> array_search($b['dependency_type'], self::BLOCKING_TYPES, true)
        );

        $passed = $blocking === [];

        $nextAction = null;
        if (! $passed) {
            $first = $blocking[0];
            $nextAction = $first['unblock_action'];
        }

        return [
            'schema'                 => self::SCHEMA,
            'plan_id'                => $planId,
            'passed'                 => $passed,
            'autonomy_status'        => $passed ? 'fully_autonomous_steady_state' : 'steady_state_human_dependency_detected',
            'blocking_dependencies'  => $blocking,
            'blocking_dependency'    => $blocking[0] ?? null,
            'optional_dependencies'  => $optional,
            'next_unblock_action'    => $nextAction,
            'remediation_hint'       => $nextAction,
        ];
    }
}
