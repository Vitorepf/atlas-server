<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Final-closure regression gate: blocks the final verdict when any ORDINARY progress path
 * marks a non_atlas actor as `steady_state_required`. Bootstrap, visibility and emergency
 * labels remain ADVISORY ONLY when final owner is atlas_native.
 *
 * Pure, facts-only, deterministic — NO disk / DB / provider calls.
 *
 * Expected facts shape:
 * {
 *   final_runtime_owner: string,
 *   paths: [
 *     { id: string, kind?: 'ordinary'|'bootstrap'|'visibility'|'emergency',
 *       label?: string, steady_state_required: [..non_atlas actor strings..] }
 *   ]
 * }
 *
 * "non_atlas actor" = any actor string NOT in {atlas_native, atlas_server}.
 */
final class AtlasSelfConstructionHumanDependencyRegressionGate
{
    public const SCHEMA = 'atlas.self_construction.human_dependency_regression_gate.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    public const ALLOWED_EXCEPTION_LABELS = ['bootstrap', 'visibility', 'emergency'];

    /** @var list<string> */
    public const ATLAS_NATIVE_ACTORS = ['atlas_native', 'atlas_server'];

    /**
     * Actors that indicate a pasted-session, manual-recovery, or non-Atlas-native execution
     * workflow. These emit a named blocker distinct from generic non-atlas actors so operators
     * and Maestro can distinguish "ordinary human dependency" from "pasted-session anti-pattern".
     *
     * @var list<string>
     */
    public const PASTED_SESSION_ANTIPATTERNS = [
        'pasted_session',
        'paste_session',
        'session_paste',
        'manual_recovery',
        'non_atlas_native_execution',
        'human_session_paste',
        'pasted_context',
    ];

    public const REGRESSION_OPERATOR_PROMPT = 'operator_prompt_required';

    public const REGRESSION_MANUAL_DECISION = 'manual_decision_required';

    public const REGRESSION_PROVIDER = 'provider_required';

    public const REGRESSION_HUMAN_RECOVERY = 'human_recovery_only';

    public const REGRESSION_UNDOCUMENTED_HANDOFF = 'undocumented_handoff';

    /** regression kind => severity. */
    private const REGRESSION_SEVERITY = [
        self::REGRESSION_PROVIDER => 'critical',
        self::REGRESSION_OPERATOR_PROMPT => 'critical',
        self::REGRESSION_HUMAN_RECOVERY => 'critical',
        self::REGRESSION_MANUAL_DECISION => 'high',
        self::REGRESSION_UNDOCUMENTED_HANDOFF => 'medium',
    ];

    /** regression kind => concrete Atlas-native replacement guidance. */
    private const REGRESSION_NATIVE_REPLACEMENT_HINT = [
        self::REGRESSION_OPERATOR_PROMPT => 'replace the operator prompt with an Atlas-native autonomous decision (gate/originator function), not a human confirmation step',
        self::REGRESSION_MANUAL_DECISION => 'replace the manual decision with a deterministic Atlas-native policy (a gate/service method), not human judgment',
        self::REGRESSION_PROVIDER => 'replace the external-provider dependency with a local/Atlas-native execution path (Hermes/local model), never a hard external-provider requirement',
        self::REGRESSION_HUMAN_RECOVERY => 'replace the human/pasted-session recovery step with an Atlas-native automated recovery routine (self-heal, retry, reclaim)',
        self::REGRESSION_UNDOCUMENTED_HANDOFF => 'name and document the actual handoff — an unnamed non-atlas actor in an ordinary path cannot be evaluated for native replacement',
    ];

    /** regression kind => concrete next action to build the Atlas-native replacement. */
    private const REGRESSION_NATIVE_REPLACEMENT_ACTION = [
        self::REGRESSION_OPERATOR_PROMPT => 'build_atlas_native_autonomous_decision_gate',
        self::REGRESSION_MANUAL_DECISION => 'codify_deterministic_atlas_native_policy_service',
        self::REGRESSION_PROVIDER => 'route_execution_through_atlas_native_or_local_model',
        self::REGRESSION_HUMAN_RECOVERY => 'implement_atlas_native_self_heal_recovery_routine',
        self::REGRESSION_UNDOCUMENTED_HANDOFF => 'name_and_document_actor_before_replacement_can_be_planned',
    ];

    /** regression kind => Atlas subsystem accountable for delivering the replacement. */
    private const REGRESSION_REPLACEMENT_OWNER = [
        self::REGRESSION_OPERATOR_PROMPT => 'self_construction_gate_layer',
        self::REGRESSION_MANUAL_DECISION => 'self_construction_policy_layer',
        self::REGRESSION_PROVIDER => 'engineering_kernel_execution_layer',
        self::REGRESSION_HUMAN_RECOVERY => 'autonomous_evolution_recovery_layer',
        self::REGRESSION_UNDOCUMENTED_HANDOFF => 'unassigned_pending_actor_documentation',
    ];

    /** regression kind => evidence refs required to prove the replacement was actually delivered. */
    private const REGRESSION_REQUIRED_PROOF_REFS = [
        self::REGRESSION_OPERATOR_PROMPT => ['tests_or_gates_result', 'autonomous_decision_receipt'],
        self::REGRESSION_MANUAL_DECISION => ['tests_or_gates_result', 'policy_decision_receipt'],
        self::REGRESSION_PROVIDER => ['tests_or_gates_result', 'local_execution_receipt'],
        self::REGRESSION_HUMAN_RECOVERY => ['tests_or_gates_result', 'self_heal_receipt'],
        self::REGRESSION_UNDOCUMENTED_HANDOFF => ['actor_documentation_ref'],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function check(array $facts): array
    {
        $blockers = [];
        $inspectedPaths = [];
        $regressions = [];

        $finalOwner = (string) ($facts['final_runtime_owner'] ?? '');
        if ($finalOwner !== 'atlas_native') {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        $paths = is_array($facts['paths'] ?? null) ? $facts['paths'] : [];
        foreach ($paths as $path) {
            if (! is_array($path)) {
                continue;
            }
            $id = (string) ($path['id'] ?? 'unknown');
            $kind = (string) ($path['kind'] ?? 'ordinary');
            $label = (string) ($path['label'] ?? '');
            $steadyStateRequired = array_values(array_map('strval', (array) ($path['steady_state_required'] ?? [])));
            $nonAtlas = array_values(array_filter(
                $steadyStateRequired,
                fn (string $actor): bool => ! in_array($actor, self::ATLAS_NATIVE_ACTORS, true),
            ));

            $isAdvisoryException = in_array($kind, self::ALLOWED_EXCEPTION_LABELS, true)
                || in_array($label, self::ALLOWED_EXCEPTION_LABELS, true);

            // A pasted-session/manual-recovery actor can never be waved through by a decorative
            // `label` claiming bootstrap/visibility when the real `kind` is ordinary or recovery —
            // otherwise any steady-state pasted-session dependency could hide behind a mislabeled
            // advisory tag while the path itself is genuinely load-bearing.
            $hasPastedSessionActor = (bool) array_intersect($nonAtlas, self::PASTED_SESSION_ANTIPATTERNS);
            if ($hasPastedSessionActor && in_array($kind, ['ordinary', 'recovery'], true)) {
                $isAdvisoryException = false;
            }

            $inspectedPaths[] = [
                'id' => $id,
                'kind' => $kind,
                'label' => $label,
                'non_atlas_steady_state_actors' => $nonAtlas,
                'advisory_exception_applied' => $isAdvisoryException && $finalOwner === 'atlas_native',
            ];

            if ($nonAtlas === []) {
                continue;
            }
            if ($isAdvisoryException && $finalOwner === 'atlas_native') {
                continue;
            }

            foreach ($nonAtlas as $actor) {
                if (in_array($actor, self::PASTED_SESSION_ANTIPATTERNS, true)) {
                    $blockingReason = sprintf('pasted_session_recovery_in_ordinary_path:path=%s:actor=%s', $id, $actor);
                } else {
                    $blockingReason = sprintf('steady_state_non_atlas_actor:path=%s:actor=%s', $id, $actor);
                }
                $blockers[] = $blockingReason;

                $kindOfRegression = $this->classifyRegression($actor);
                $regressions[] = [
                    'kind' => $kindOfRegression,
                    'path_id' => $id,
                    'actor' => $actor,
                    'severity' => self::REGRESSION_SEVERITY[$kindOfRegression],
                    'blocking_reason' => $blockingReason,
                    'native_replacement_hint' => self::REGRESSION_NATIVE_REPLACEMENT_HINT[$kindOfRegression],
                    'native_replacement_action' => self::REGRESSION_NATIVE_REPLACEMENT_ACTION[$kindOfRegression],
                    'replacement_owner' => self::REGRESSION_REPLACEMENT_OWNER[$kindOfRegression],
                    'required_proof_refs' => self::REGRESSION_REQUIRED_PROOF_REFS[$kindOfRegression],
                ];
            }
        }

        $passed = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $passed ? self::STATUS_PASSED : self::STATUS_BLOCKED,
            'passed' => $passed,
            'blockers' => $blockers,
            'regressions' => $regressions,
            'allowed_exception_labels' => self::ALLOWED_EXCEPTION_LABELS,
            'inspected_paths' => $inspectedPaths,
            'proof_summary' => sprintf(
                'final_owner=%s paths=%d blockers=%d',
                $finalOwner,
                count($inspectedPaths),
                count($blockers),
            ),
        ];
    }

    /** Classifies a non-atlas actor string into one of the 5 named regression kinds. */
    private function classifyRegression(string $actor): string
    {
        $lower = strtolower($actor);

        if (in_array($actor, self::PASTED_SESSION_ANTIPATTERNS, true)
            || str_contains($lower, 'recovery')
            || str_contains($lower, 'paste')
            || str_contains($lower, 'session')) {
            return self::REGRESSION_HUMAN_RECOVERY;
        }
        if (str_contains($lower, 'provider') || str_contains($lower, 'claude') || str_contains($lower, 'codex') || str_contains($lower, 'external')) {
            return self::REGRESSION_PROVIDER;
        }
        if (str_contains($lower, 'operator') || str_contains($lower, 'prompt')) {
            return self::REGRESSION_OPERATOR_PROMPT;
        }
        if (str_contains($lower, 'manual') || str_contains($lower, 'decision') || str_contains($lower, 'review') || str_contains($lower, 'approve')) {
            return self::REGRESSION_MANUAL_DECISION;
        }

        return self::REGRESSION_UNDOCUMENTED_HANDOFF;
    }
}
