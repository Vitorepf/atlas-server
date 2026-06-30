<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure orchestrator: walks pre-computed organ results through a deterministic
 * phase pipeline and emits one consolidated next-batch decision.
 *
 * Phase order: context → proposal → critique → value → readiness → queue_decision
 *
 * Fail-closed rules — a phase BLOCKS when:
 *   - missing from organ_results
 *   - its result has stale=true
 *   - its result has contradictory=true
 *
 * On block: sets blocking_phase, final_decision='blocked', and stops.
 * On full pass: final_decision='proceed'.
 *
 * next_batch_constraints are aggregated from each passing phase that carries
 * a 'constraints' list.
 */
final class AtlasExternalBrainOrganMeshOrchestrator
{
    public const SCHEMA = 'atlas.external_brain.organ_mesh_orchestrator.v1';

    public const PHASES = ['context', 'proposal', 'critique', 'value', 'readiness', 'queue_decision'];

    /**
     * @param  array<string,mixed>  $input  organ_results keyed by phase name
     * @return array<string,mixed>
     */
    public function orchestrate(array $input): array
    {
        $organResults = is_array($input['organ_results'] ?? null) ? $input['organ_results'] : [];

        $phaseOutputs = [];
        $blockingPhase = null;
        $nextBatchConstraints = [];
        $compactTrace = [];

        foreach (self::PHASES as $phase) {
            if (! array_key_exists($phase, $organResults)) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'missing'];
                break;
            }

            $result = $organResults[$phase];

            if (! empty($result['stale'])) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'stale'];
                break;
            }

            if (! empty($result['contradictory'])) {
                $blockingPhase = $phase;
                $compactTrace[] = ['phase' => $phase, 'status' => 'contradictory'];
                break;
            }

            $phaseOutputs[$phase] = $result;
            $compactTrace[] = ['phase' => $phase, 'status' => 'passed'];

            if (is_array($result['constraints'] ?? null)) {
                foreach ($result['constraints'] as $constraint) {
                    $nextBatchConstraints[] = $constraint;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'phase_outputs' => $phaseOutputs,
            'blocking_phase' => $blockingPhase,
            'final_decision' => $blockingPhase === null ? 'proceed' : 'blocked',
            'next_batch_constraints' => $nextBatchConstraints,
            'compact_trace' => $compactTrace,
        ];
    }
}
