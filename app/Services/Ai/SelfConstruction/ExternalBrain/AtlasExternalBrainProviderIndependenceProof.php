<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proves that frontier providers are optional accelerators and NOT required for steady-state
 * task origination, validation, rollback, or learning.
 *
 * A PHASE FAILS INDEPENDENCE if any of:
 *   - requires_live_provider = true
 *   - requires_manual_provider_selection = true
 *   - has_provider_specific_traces = true
 *   - requires_operator_intervention = true   (steady-state must be fully autonomous)
 *   - requires_frontier_only_judgement = true (local fallback must cover judgement)
 *
 * A PHASE IS INDEPENDENT if:
 *   - None of the above failure conditions are true
 *   - Has at least one fallback path (local_evidence OR scaffold OR benchmark OR rollback)
 *
 * MISSING PROOF: a phase missing any of the 4 coverage types is flagged with which are absent.
 *
 * MANDATORY PHASES (AC3): task_origination, task_validation, outcome_learning,
 *   queue_self_healing, scaffold_promotion, rollback, autonomy_stop_go.
 *   Any missing mandatory phase is added to missing_proofs with all 4 coverage types absent.
 *
 * INDEPENDENT (overall) = true only when:
 *   - All evaluated phases are independent
 *   - provider_required_phases is empty
 *   - No phase has missing_proofs (including any absent mandatory phases)
 *
 * INPUT:
 *   proof_claims: list<{
 *     phase:                              string
 *     has_local_evidence_path:            bool
 *     has_scaffold_fallback:              bool
 *     has_benchmark_coverage:             bool
 *     has_rollback_path:                  bool
 *     requires_live_provider?:            bool  (default false)
 *     requires_manual_provider_selection?: bool  (default false)
 *     has_provider_specific_traces?:      bool  (default false)
 *     optional_frontier_accelerators?:    list<string>  (default [])
 *   }>
 *
 * OUTPUT:
 *   { schema, independent, provider_required_phases, fallback_coverage,
 *     optional_frontier_accelerators, missing_proofs }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainProviderIndependenceProof
{
    public const SCHEMA = 'atlas.external_brain.provider_independence_proof.v1';

    public const CLASSIFICATION_PROVIDER_INDEPENDENT = 'provider_independent';
    public const CLASSIFICATION_PROVIDER_ACCELERATED = 'provider_accelerated';
    public const CLASSIFICATION_PROVIDER_DEPENDENT = 'provider_dependent';

    public const MANDATORY_PHASES = [
        'task_origination',
        'task_validation',
        'outcome_learning',
        'queue_self_healing',
        'scaffold_promotion',
        'rollback',
        'autonomy_stop_go',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prove(array $input): array
    {
        $claims = is_array($input['proof_claims'] ?? null) ? $input['proof_claims'] : [];

        $providerRequiredPhases = [];
        $fallbackCoverage       = [];
        $optionalFrontierAccel  = [];
        $missingProofs          = [];
        $evaluatedPhases        = [];
        $circuitClassifications = [];

        foreach ($claims as $claim) {
            if (! is_array($claim) || ! isset($claim['phase'])) {
                continue;
            }

            $phase               = (string) $claim['phase'];
            $evaluatedPhases[]   = $phase;
            $localEvidence       = (bool) ($claim['has_local_evidence_path']           ?? false);
            $scaffold            = (bool) ($claim['has_scaffold_fallback']             ?? false);
            $benchmark           = (bool) ($claim['has_benchmark_coverage']            ?? false);
            $rollback            = (bool) ($claim['has_rollback_path']                 ?? false);
            $requiresLive        = (bool) ($claim['requires_live_provider']            ?? false);
            $requiresManual      = (bool) ($claim['requires_manual_provider_selection'] ?? false);
            $hasTraces           = (bool) ($claim['has_provider_specific_traces']      ?? false);
            $requiresOperator    = (bool) ($claim['requires_operator_intervention']    ?? false);
            $requiresFrontierOnly = (bool) ($claim['requires_frontier_only_judgement'] ?? false);
            $accelerators        = is_array($claim['optional_frontier_accelerators'] ?? null)
                ? $claim['optional_frontier_accelerators']
                : [];

            $fallbackCoverage[$phase] = [
                'local_evidence' => $localEvidence,
                'scaffold'       => $scaffold,
                'benchmark'      => $benchmark,
                'rollback'       => $rollback,
            ];

            $optionalFrontierAccel[$phase] = $accelerators;

            // Provider required?
            $reasons = [];
            if ($requiresLive) {
                $reasons[] = 'requires_live_provider';
            }
            if ($requiresManual) {
                $reasons[] = 'requires_manual_provider_selection';
            }
            if ($hasTraces) {
                $reasons[] = 'has_provider_specific_traces';
            }
            if ($requiresOperator) {
                $reasons[] = 'requires_operator_intervention';
            }
            if ($requiresFrontierOnly) {
                $reasons[] = 'requires_frontier_only_judgement';
            }
            if ($reasons !== []) {
                $providerRequiredPhases[] = ['phase' => $phase, 'reasons' => $reasons];
            }

            // Missing proofs.
            $missing = [];
            if (! $localEvidence) {
                $missing[] = 'has_local_evidence_path';
            }
            if (! $scaffold) {
                $missing[] = 'has_scaffold_fallback';
            }
            if (! $benchmark) {
                $missing[] = 'has_benchmark_coverage';
            }
            if (! $rollback) {
                $missing[] = 'has_rollback_path';
            }
            if ($missing !== []) {
                $missingProofs[] = ['phase' => $phase, 'missing_coverage' => $missing];
            }

            $isProviderDependent = $reasons !== [];
            $classification = match (true) {
                $isProviderDependent => self::CLASSIFICATION_PROVIDER_DEPENDENT,
                $accelerators !== [] => self::CLASSIFICATION_PROVIDER_ACCELERATED,
                default => self::CLASSIFICATION_PROVIDER_INDEPENDENT,
            };
            $circuitClassifications[] = [
                'phase' => $phase,
                'classification' => $classification,
                'missing_fallback' => $isProviderDependent && $missing !== [],
                'missing_fallback_types' => $isProviderDependent ? $missing : [],
            ];
        }

        // AC3: any mandatory phase not present in claims → missing_proofs
        $allMissing = ['has_local_evidence_path', 'has_scaffold_fallback', 'has_benchmark_coverage', 'has_rollback_path'];
        foreach (self::MANDATORY_PHASES as $mandatory) {
            if (! in_array($mandatory, $evaluatedPhases, true)) {
                $missingProofs[] = ['phase' => $mandatory, 'missing_coverage' => $allMissing];
            }
        }

        $independent = $providerRequiredPhases === [] && $missingProofs === [];

        return [
            'schema'                         => self::SCHEMA,
            'independent'                    => $independent,
            'provider_required_phases'       => $providerRequiredPhases,
            'fallback_coverage'              => $fallbackCoverage,
            'optional_frontier_accelerators' => $optionalFrontierAccel,
            'missing_proofs'                 => $missingProofs,
            'circuit_classifications'        => $circuitClassifications,
        ];
    }
}
