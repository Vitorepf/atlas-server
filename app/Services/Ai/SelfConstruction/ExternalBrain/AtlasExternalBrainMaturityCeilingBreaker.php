<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ceiling-breaker. Detects when incremental hardening has stalled
 * autonomous capability growth and proposes the safest qualifying jump.
 *
 * Ceiling detection (AC2):
 *   A ceiling is detected when the count of recent tasks that do NOT unlock
 *   a new capability (unlocks_new_capability !== true) equals or exceeds
 *   stagnation_threshold (default 5).
 *
 * Jump qualification (AC3):
 *   A proposed_capability_jump is eligible only when ALL hold:
 *     - blast_radius  <= MAX_BLAST_RADIUS (0.50)
 *     - risk_score    <= MAX_RISK_SCORE    (0.60)
 *     - proof_gates is a non-empty list
 *     - prerequisites list is non-empty OR prerequisites_met list exists
 *   The best eligible jump is the one with the lowest risk_score (ties broken
 *   by lowest blast_radius). Ineligible jumps appear in rejected_jumps.
 *
 * rejected_incremental_tasks: recent tasks with unlocks_new_capability !== true (AC4).
 *
 * AC4 outputs: ceiling_detected, proposed_jump, prerequisites, proof_gates,
 *   rejected_incremental_tasks (plus ceiling_evidence, blast_radius_within_bounds,
 *   risk_within_bounds, rejected_jumps for completeness).
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainMaturityCeilingBreaker
{
    public const SCHEMA = 'atlas.external_brain.maturity_ceiling_breaker.v1';

    private const STAGNATION_DEFAULT      = 5;
    private const MAX_BLAST_RADIUS        = 0.50;
    private const MAX_RISK_SCORE          = 0.60;
    private const HIGH_SATURATION_THRESHOLD = 0.60;

    /** Refuse to declare a ceiling without at least this many recent task samples. */
    private const MIN_SAMPLE_SIZE = 3;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function analyze(array $facts): array
    {
        $recentTasks   = is_array($facts['recent_tasks'] ?? null) ? $facts['recent_tasks'] : [];
        $threshold     = max(1, (int) ($facts['stagnation_threshold'] ?? self::STAGNATION_DEFAULT));
        $proposedJumps = is_array($facts['proposed_capability_jumps'] ?? null)
            ? $facts['proposed_capability_jumps'] : [];

        // Separate tasks by capability-unlock status; collect family counts.
        $nonUnlockingTasks = [];
        $metricDeltas      = [];
        $unlockCount       = 0;
        $familyCounts      = [];

        foreach ($recentTasks as $task) {
            $unlocks = (bool) ($task['unlocks_new_capability'] ?? false);
            if ($unlocks) {
                $unlockCount++;
            } else {
                $nonUnlockingTasks[] = $task;
            }
            $metricDeltas[] = max(0.0, (float) ($task['metric_delta'] ?? 0.0));
            // Only tasks with an explicit non-empty family contribute to concentration.
            $family = (string) ($task['task_family'] ?? '');
            if ($family !== '') {
                $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            }
        }

        $totalCount        = count($recentTasks);
        $nonUnlockingCount = count($nonUnlockingTasks);

        // AC1: 4 new metrics.
        $structuralUnlockRate = $totalCount > 0
            ? round($unlockCount / $totalCount, 4) : 0.0;

        $familyTaskCount    = array_sum($familyCounts);
        $repeatedFamilyRate = $familyTaskCount > 0
            ? round(max($familyCounts) / $familyTaskCount, 4) : 0.0;

        $saturationScore = round(($repeatedFamilyRate + (1.0 - $structuralUnlockRate)) / 2.0, 4);

        // AC2: ceiling triggered by stagnation count OR high saturation, but never
        // declared from too little data — too few samples means "not enough evidence",
        // not "ceiling reached".
        $insufficientData  = $totalCount < self::MIN_SAMPLE_SIZE;
        $stagnationCeiling = $nonUnlockingCount >= $threshold;
        $saturationCeiling = $saturationScore >= self::HIGH_SATURATION_THRESHOLD;
        $ceilingDetected   = ! $insufficientData && ($stagnationCeiling || $saturationCeiling);

        $marginalGainAvg = count($metricDeltas) > 0
            ? round(array_sum($metricDeltas) / count($metricDeltas), 6)
            : 0.0;

        // Qualify proposed jumps.
        $eligibleJumps  = [];
        $rejectedJumps  = [];

        foreach ($proposedJumps as $jump) {
            $name         = (string) ($jump['name'] ?? '');
            $blastRadius  = max(0.0, min(1.0, (float) ($jump['blast_radius'] ?? 1.0)));
            $riskScore    = max(0.0, min(1.0, (float) ($jump['risk_score']   ?? 1.0)));
            $proofGates   = array_values((array) ($jump['proof_gates']   ?? []));
            $prerequisites = array_values((array) ($jump['prerequisites'] ?? []));

            $blastOk  = $blastRadius <= self::MAX_BLAST_RADIUS;
            $riskOk   = $riskScore   <= self::MAX_RISK_SCORE;
            $gatesOk  = count($proofGates) > 0;

            if ($blastOk && $riskOk && $gatesOk) {
                $eligibleJumps[] = [
                    'name'             => $name,
                    'prerequisites'    => $prerequisites,
                    'proof_gates'      => $proofGates,
                    'blast_radius'     => $blastRadius,
                    'risk_score'       => $riskScore,
                ];
            } else {
                $rejectedJumps[] = [
                    'name'    => $name,
                    'reasons' => array_filter([
                        $blastOk ? null : 'blast_radius_exceeds_bound',
                        $riskOk  ? null : 'risk_score_exceeds_bound',
                        $gatesOk ? null : 'no_proof_gates_defined',
                    ]),
                ];
            }
        }

        // Pick best eligible jump (lowest risk, then lowest blast).
        usort($eligibleJumps, static fn (array $a, array $b): int
            => $a['risk_score'] <=> $b['risk_score'] ?: $a['blast_radius'] <=> $b['blast_radius']);

        $proposedJump = $eligibleJumps[0] ?? null;

        $ambitionJumpScore = $proposedJump !== null
            ? round((1.0 - $proposedJump['risk_score']) * (1.0 - $proposedJump['blast_radius']), 4)
            : 0.0;

        // AC3: ordered unlock_chain — every eligible jump, in selection order, each carrying
        // its own expected_compound_lift, not just the single best candidate.
        $unlockChain = [];
        foreach ($eligibleJumps as $order => $jump) {
            $unlockChain[] = $jump + [
                'order'                  => $order + 1,
                'expected_compound_lift' => round((1.0 - $jump['risk_score']) * (1.0 - $jump['blast_radius']), 4),
            ];
        }

        $incrementalRejectionReason = $nonUnlockingCount > 0
            ? "non_unlocking_task_count={$nonUnlockingCount}:tasks_did_not_unlock_a_new_capability_so_excluded_from_the_jump_chain"
            : 'no_incremental_tasks_were_rejected';

        return [
            'schema_version'               => self::SCHEMA,
            'ceiling_detected'             => $ceilingDetected,
            'ceiling_evidence'             => [
                'non_unlocking_task_count'  => $nonUnlockingCount,
                'marginal_gain_average'     => $marginalGainAvg,
                'stagnation_threshold_used' => $threshold,
                'structural_unlock_rate'    => $structuralUnlockRate,
                'repeated_family_rate'      => $repeatedFamilyRate,
                'saturation_score'          => $saturationScore,
                'ambition_jump_score'       => $ambitionJumpScore,
                'insufficient_data'         => $insufficientData,
                'total_task_count'          => $totalCount,
                'min_sample_size'           => self::MIN_SAMPLE_SIZE,
            ],
            'proposed_jump'                => $proposedJump,
            'prerequisites'                => $proposedJump['prerequisites'] ?? [],
            'proof_gates'                  => $proposedJump['proof_gates']   ?? [],
            'blast_radius_within_bounds'   => $proposedJump !== null ? ($proposedJump['blast_radius'] <= self::MAX_BLAST_RADIUS) : false,
            'risk_within_bounds'           => $proposedJump !== null ? ($proposedJump['risk_score']   <= self::MAX_RISK_SCORE)   : false,
            'rejected_incremental_tasks'   => $nonUnlockingTasks,
            'rejected_jumps'               => $rejectedJumps,
            'unlock_chain'                 => $unlockChain,
            'incremental_rejection_reason' => $incrementalRejectionReason,
        ];
    }
}
