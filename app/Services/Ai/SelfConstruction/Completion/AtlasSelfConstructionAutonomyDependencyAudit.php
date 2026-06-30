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

        foreach ($evidence as $row) {
            if (! is_array($row)) {
                continue;
            }
            $stepId = (string) ($row['step_id'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            $role = (string) ($row['role'] ?? '');

            if ($stepId !== '' && isset($seenStepIds[$stepId])) {
                $blockers[] = 'duplicate_step_id:'.$stepId;

                continue;
            }
            if ($stepId !== '') {
                $seenStepIds[$stepId] = true;
            }

            if (! in_array($kind, self::VALID_KINDS, true)) {
                $blockers[] = 'unknown_kind:'.$stepId.':'.$kind;

                continue;
            }
            if (! in_array($role, self::VALID_ROLES, true)) {
                $blockers[] = 'unknown_role:'.$stepId.':'.$role;

                continue;
            }

            if ($kind === self::KIND_STEADY_STATE) {
                if ($stepId !== '') {
                    $seenSteadyStatePhases[$stepId] = true;
                }
                if ($role !== self::ROLE_ATLAS) {
                    $blockers[] = 'steady_state_non_atlas_dependency:'.$stepId.':'.$role;
                    $steadyStateDependencies[] = ['step_id' => $stepId, 'role' => $role];
                }
            } else {
                // bootstrap + emergency — allowed even with non-atlas roles.
                $allowedVisibility[] = ['step_id' => $stepId, 'kind' => $kind, 'role' => $role];
            }
        }

        foreach ($requiredSteadyStatePhases as $required) {
            if (! isset($seenSteadyStatePhases[$required])) {
                $blockers[] = 'missing_steady_state_phase:'.$required;
            }
        }

        $atlasNative = $blockers === [];

        return [
            'schema_version' => self::SCHEMA,
            'atlas_native' => $atlasNative,
            'blockers' => array_values($blockers),
            'allowed_visibility' => $allowedVisibility,
            'steady_state_dependencies' => $steadyStateDependencies,
        ];
    }
}
