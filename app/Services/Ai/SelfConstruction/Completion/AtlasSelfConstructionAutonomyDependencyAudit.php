<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Pure audit. Determines whether the completion evidence for Self-Construction still relies on
 * operator, human, external worker, or provider-owned STEADY-STATE steps. Bootstrap visibility and
 * emergency controls are NON-BLOCKING — but only when ordinary (steady-state) progress remains
 * Atlas-native.
 *
 * Input evidence list shape:
 *   list<{step_id, kind, role}>  with kind ∈ {steady_state, bootstrap, emergency} and
 *                                 role ∈ {atlas_native, operator, human, external_worker, provider}.
 *
 * Output: {schema_version, atlas_native, blockers, allowed_visibility, steady_state_dependencies}
 *
 * atlas_native is true only when EVERY steady_state step has role=atlas_native. Bootstrap/emergency
 * steps may have non-atlas roles without blocking.
 */
final class AtlasSelfConstructionAutonomyDependencyAudit
{
    public const SCHEMA = 'atlas.self_construction.autonomy_dependency_audit.v1';

    public const KIND_STEADY_STATE = 'steady_state';

    public const KIND_BOOTSTRAP = 'bootstrap';

    public const KIND_EMERGENCY = 'emergency';

    public const ROLE_ATLAS = 'atlas_native';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_HUMAN = 'human';

    public const ROLE_EXTERNAL_WORKER = 'external_worker';

    public const ROLE_PROVIDER = 'provider';

    public const VALID_KINDS = [self::KIND_STEADY_STATE, self::KIND_BOOTSTRAP, self::KIND_EMERGENCY];

    public const NON_ATLAS_ROLES = [self::ROLE_OPERATOR, self::ROLE_HUMAN, self::ROLE_EXTERNAL_WORKER, self::ROLE_PROVIDER];

    public const VALID_ROLES = [self::ROLE_ATLAS, self::ROLE_OPERATOR, self::ROLE_HUMAN, self::ROLE_EXTERNAL_WORKER, self::ROLE_PROVIDER];

    /**
     * @param  list<array<string,mixed>>  $evidence
     * @param  list<string>               $requiredSteadyStatePhases  step_ids that MUST appear as steady_state
     * @return array<string,mixed>
     */
    public function audit(array $evidence, array $requiredSteadyStatePhases = []): array
    {
        if ($evidence === []) {
            return [
                'schema_version' => self::SCHEMA,
                'atlas_native' => false,
                'blockers' => ['empty_evidence'],
                'allowed_visibility' => [],
                'steady_state_dependencies' => [],
            ];
        }

        $blockers = [];
        $allowedVisibility = [];
        $steadyStateDependencies = [];
        $seenStepIds = [];
        $seenSteadyStatePhases = [];
        $remediationHints = [];
        $providerLeakFloor = [];
        $structuredBlockers = [];
        $requiredPhaseSet = array_flip($requiredSteadyStatePhases);

        foreach ($evidence as $row) {
            if (! is_array($row)) {
                continue;
            }
            $stepId = (string) ($row['step_id'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            $role = (string) ($row['role'] ?? '');

            if ($stepId !== '' && isset($seenStepIds[$stepId])) {
                $blockerKey = 'duplicate_step_id:'.$stepId;
                $blockers[] = $blockerKey;
                $structuredBlockers[] = [
                    'phase' => $stepId,
                    'role' => $role,
                    'replacement_needed' => 'remove_duplicate_step_id',
                ];

                continue;
            }
            if ($stepId !== '') {
                $seenStepIds[$stepId] = true;
            }

            if (! in_array($kind, self::VALID_KINDS, true)) {
                $blockerKey = 'unknown_kind:'.$stepId.':'.$kind;
                $blockers[] = $blockerKey;
                $structuredBlockers[] = [
                    'phase' => $stepId,
                    'role' => $role,
                    'replacement_needed' => 'replace_with_valid_kind',
                ];

                continue;
            }
            if (! in_array($role, self::VALID_ROLES, true)) {
                $blockerKey = 'unknown_role:'.$stepId.':'.$role;
                $blockers[] = $blockerKey;
                $structuredBlockers[] = [
                    'phase' => $stepId,
                    'role' => $role,
                    'replacement_needed' => 'replace_with_valid_role',
                ];

                continue;
            }

            if ($kind === self::KIND_STEADY_STATE) {
                if ($stepId !== '') {
                    $seenSteadyStatePhases[$stepId] = true;
                }
                if ($role !== self::ROLE_ATLAS) {
                    $b = 'steady_state_non_atlas_dependency:'.$stepId.':'.$role;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'replace_with_atlas_native_capability',
                    ];
                    $remediationHints[$b] = 'replace_non_atlas_role_with_atlas_native_capability_for_phase:'.$stepId;
                    $steadyStateDependencies[] = ['step_id' => $stepId, 'role' => $role];
                    // Group by role for provider_leak_floor
                    $providerLeakFloor[$role][] = [
                        'step_id' => $stepId,
                        'phase' => $stepId,
                        'remediation' => 'replace_non_atlas_role_with_atlas_native_capability_for_phase:'.$stepId,
                    ];
                }

                $isRequiredPhase = $stepId !== '' && isset($requiredPhaseSet[$stepId]);

                // Projection staleness check. Required phases need an explicit 'fresh' status — a
                // required phase whose projection is simply absent is not part of a complete chain.
                $projStatus = (string) ($row['projection_status'] ?? '');
                if ($projStatus === 'stale') {
                    $b = 'stale_projection:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'run_projection_refresh',
                    ];
                    $remediationHints[$b] = 'run_projection_refresh_for_phase:'.$stepId;
                } elseif ($projStatus === 'unavailable') {
                    $b = 'unavailable_projection:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'provision_projection_source',
                    ];
                    $remediationHints[$b] = 'provision_projection_source_for_phase:'.$stepId;
                } elseif ($isRequiredPhase && $projStatus !== 'fresh') {
                    $b = 'missing_projection_status:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'record_fresh_projection_status',
                    ];
                    $remediationHints[$b] = 'record_fresh_projection_status_for_required_phase:'.$stepId;
                }

                // Queue evidence check. Required phases need an explicit 'available' value.
                $queueEv = (string) ($row['queue_evidence'] ?? '');
                if ($queueEv === 'unavailable') {
                    $b = 'unavailable_queue_evidence:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'restore_queue_evidence',
                    ];
                    $remediationHints[$b] = 'restore_queue_evidence_for_phase:'.$stepId;
                } elseif ($isRequiredPhase && $queueEv !== 'available') {
                    $b = 'missing_queue_evidence:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'record_available_queue_evidence',
                    ];
                    $remediationHints[$b] = 'record_available_queue_evidence_for_required_phase:'.$stepId;
                }

                // Runtime evidence check. Required phases need an explicit 'available' value.
                $runtimeEv = (string) ($row['runtime_evidence'] ?? '');
                if ($runtimeEv === 'unavailable') {
                    $b = 'unavailable_runtime_evidence:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'collect_runtime_evidence',
                    ];
                    $remediationHints[$b] = 'collect_runtime_evidence_for_phase:'.$stepId;
                } elseif ($isRequiredPhase && $runtimeEv !== 'available') {
                    $b = 'missing_runtime_evidence:'.$stepId;
                    $blockers[] = $b;
                    $structuredBlockers[] = [
                        'phase' => $stepId,
                        'role' => $role,
                        'replacement_needed' => 'record_available_runtime_evidence',
                    ];
                    $remediationHints[$b] = 'record_available_runtime_evidence_for_required_phase:'.$stepId;
                }
            } else {
                // bootstrap + emergency — allowed even with non-atlas roles.
                $allowedVisibility[] = ['step_id' => $stepId, 'kind' => $kind, 'role' => $role];
            }
        }

        foreach ($requiredSteadyStatePhases as $required) {
            if (! isset($seenSteadyStatePhases[$required])) {
                $b = 'missing_steady_state_phase:'.$required;
                $blockers[] = $b;
                $structuredBlockers[] = [
                    'phase' => $required,
                    'role' => null,
                    'replacement_needed' => 'add_steady_state_evidence_entry',
                ];
                $remediationHints[$b] = 'add_steady_state_evidence_entry_for_phase:'.$required;
            }
        }

        $atlasNative = $blockers === [];

        return [
            'schema_version'           => self::SCHEMA,
            'atlas_native'             => $atlasNative,
            'autonomy_ready'           => $atlasNative,
            'blockers'                 => array_values($blockers),
            'structured_blockers'      => $structuredBlockers,
            'allowed_visibility'       => $allowedVisibility,
            'steady_state_dependencies' => $steadyStateDependencies,
            'remediation_hints'        => $remediationHints,
            'provider_leak_floor'      => $providerLeakFloor,
        ];
    }
}
