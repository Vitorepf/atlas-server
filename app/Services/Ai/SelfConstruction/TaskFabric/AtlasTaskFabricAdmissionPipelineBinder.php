<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure batch-pipeline binder: runs every candidate spec through the brutal
 * value admission gate and returns admitted specs, rejected specs, rejection
 * telemetry and an aggregate batch_value_score before any enqueue attempt.
 *
 * Delegates individual admission decisions to AtlasTaskFabricBrutalValueAdmissionGate.
 *
 * INPUT:
 *   candidates[]   — each with task_packet_id, allowed_files, acceptance_criteria,
 *                    required_evidence, objective, compound_impact_score,
 *                    give_back_risk_score, target, known_targets, is_template_farm
 *   shared_facts?  — optional shared admission facts (known_targets, thresholds)
 *                    merged into every candidate before the gate runs
 *
 * OUTPUT:
 *   admitted[]          — specs that passed; preserves all original fields
 *   rejected[]          — specs that failed; includes gate_reasons[]
 *   rejection_summary   — count per rejection reason across the batch
 *   batch_value_score   — avg value_score of admitted candidates (null if none admitted)
 *
 * PURE / DETERMINISTIC. Never enqueues, writes files, calls providers, or mutates state.
 */
final class AtlasTaskFabricAdmissionPipelineBinder
{
    public const SCHEMA = 'atlas.task_fabric.admission_pipeline_binder.v1';

    public function __construct(
        private readonly AtlasTaskFabricBrutalValueAdmissionGate $gate = new AtlasTaskFabricBrutalValueAdmissionGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input  candidates + optional shared_facts
     * @return array<string,mixed>
     */
    public function filter(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $sharedFacts = is_array($input['shared_facts'] ?? null) ? $input['shared_facts'] : [];

        $admitted = [];
        $rejected = [];
        $rejectionSummary = [];
        $valueSum = 0.0;

        foreach ($candidates as $candidate) {
            $merged = array_merge($candidate, $sharedFacts);
            $verdict = $this->gate->decide($merged);

            if ($verdict['admitted'] ?? false) {
                $admitted[] = $this->preservedFields($candidate, $verdict);
                $valueSum += (float) ($verdict['value_score'] ?? 0.0);
            } else {
                $gateReasons = is_array($verdict['rejection_reasons'] ?? null) ? $verdict['rejection_reasons'] : [];
                $rejected[] = array_merge(
                    $this->preservedFields($candidate, $verdict),
                    ['gate_reasons' => $gateReasons],
                );
                foreach ($gateReasons as $reason) {
                    $rejectionSummary[$reason] = ($rejectionSummary[$reason] ?? 0) + 1;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'admitted' => $admitted,
            'rejected' => $rejected,
            'rejection_summary' => $rejectionSummary,
            'batch_value_score' => count($admitted) > 0 ? round($valueSum / count($admitted), 4) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function preservedFields(array $candidate, array $verdict): array
    {
        return [
            'task_packet_id' => $candidate['task_packet_id'] ?? null,
            'allowed_files' => $candidate['allowed_files'] ?? [],
            'acceptance_criteria' => $candidate['acceptance_criteria'] ?? [],
            'required_evidence' => $candidate['required_evidence'] ?? [],
            'objective' => $candidate['objective'] ?? '',
            'value_score' => $verdict['value_score'] ?? null,
        ];
    }
}
