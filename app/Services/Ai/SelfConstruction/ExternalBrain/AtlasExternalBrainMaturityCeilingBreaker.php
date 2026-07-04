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
            $prerequisitesMet = array_values((array) ($jump['prerequisites_met'] ?? []));
            $targetArea    = (string) ($jump['target_area']     ?? '');
            $evidenceGap   = (string) ($jump['evidence_gap']    ?? '');
            $expectedUnlock = (string) ($jump['expected_unlock'] ?? '');
            $isCosmetic   = (bool) ($jump['is_cosmetic']     ?? false);
            $isWrapperOnly = (bool) ($jump['is_wrapper_only'] ?? false);

            $blastOk  = $blastRadius <= self::MAX_BLAST_RADIUS;
            $riskOk   = $riskScore   <= self::MAX_RISK_SCORE;
            $gatesOk  = count($proofGates) > 0;
            // A jump must name real prerequisites, OR already have proof that its prerequisites
            // were met — a jump with neither is an unfounded leap, not a proof-gated capability jump.
            $prereqOk = count($prerequisites) > 0 || count($prerequisitesMet) > 0;
            // AC: cosmetic/wrapper-only additions are never structural ceiling breakers,
            // no matter how safe their blast_radius/risk_score/proof_gates look.
            $substantiveOk = ! $isCosmetic && ! $isWrapperOnly;

            if ($blastOk && $riskOk && $gatesOk && $prereqOk && $substantiveOk) {
                $eligibleJumps[] = [
                    'name'             => $name,
                    'prerequisites'    => $prerequisites,
                    'proof_gates'      => $proofGates,
                    'blast_radius'     => $blastRadius,
                    'risk_score'       => $riskScore,
                    'target_area'      => $targetArea,
                    'evidence_gap'     => $evidenceGap,
                    'expected_unlock'  => $expectedUnlock,
                ];
            } else {
                $rejectedJumps[] = [
                    'name'    => $name,
                    'reasons' => array_filter([
                        $blastOk ? null : 'blast_radius_exceeds_bound',
                        $riskOk  ? null : 'risk_score_exceeds_bound',
                        $gatesOk ? null : 'no_proof_gates_defined',
                        $prereqOk ? null : 'no_prerequisites_or_prerequisites_met',
                        $substantiveOk ? null : 'cosmetic_or_wrapper_only_addition_not_a_ceiling_breaker',
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

        // AC: a single, unambiguous status — ceiling_reached only when both the ceiling
        // is detected AND a concrete, substantive leap is actually available to take.
        $ceilingStatus = match (true) {
            $insufficientData => 'insufficient_data',
            $ceilingDetected && $proposedJump !== null => 'ceiling_reached',
            $ceilingDetected => 'ceiling_detected_no_leap_available',
            default => 'not_at_ceiling',
        };

        // next_leverage_moves: concrete moves across context, proof, task_fabric, model_amplifier, simplification
        $nextLeverageMoves = $this->deriveNextLeverageMoves($recentTasks, $eligibleJumps, $rejectedJumps);

        // plateau_claim_allowed: false when unexplored high-leverage surfaces remain
        $plateauClaimAllowed = count($nextLeverageMoves) === 0 && ! $ceilingDetected;

        // ceiling_type: categorize the ceiling
        $ceilingType = match (true) {
            $insufficientData => 'insufficient_data',
            $stagnationCeiling && $saturationCeiling => 'stagnation_and_saturation',
            $stagnationCeiling => 'stagnation',
            $saturationCeiling => 'saturation',
            default => 'none',
        };

        // evidence_refs: references to evidence supporting the analysis
        $evidenceRefs = array_values(array_filter(array_map(
            static fn (array $t): ?string => (string) ($t['evidence_ref'] ?? ''),
            $recentTasks,
        )));

        // chosen_move: the best eligible jump
        $chosenMove = $proposedJump;

        // rejected_moves: rejected jumps with reasons
        $rejectedMoves = $rejectedJumps;

        return [
            'schema_version'               => self::SCHEMA,
            'ceiling_detected'             => $ceilingDetected,
            'ceiling_status'               => $ceilingStatus,
            'ceiling_type'                 => $ceilingType,
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
            'evidence_refs'                => $evidenceRefs,
            'proposed_jump'                => $proposedJump,
            'chosen_move'                  => $chosenMove,
            'prerequisites'                => $proposedJump['prerequisites'] ?? [],
            'proof_gates'                  => $proposedJump['proof_gates']   ?? [],
            'blast_radius_within_bounds'   => $proposedJump !== null ? ($proposedJump['blast_radius'] <= self::MAX_BLAST_RADIUS) : false,
            'risk_within_bounds'           => $proposedJump !== null ? ($proposedJump['risk_score']   <= self::MAX_RISK_SCORE)   : false,
            'rejected_incremental_tasks'   => $nonUnlockingTasks,
            'rejected_jumps'               => $rejectedJumps,
            'rejected_moves'               => $rejectedMoves,
            'unlock_chain'                 => $unlockChain,
            'incremental_rejection_reason' => $incrementalRejectionReason,
            'next_leverage_moves'          => $nextLeverageMoves,
            'plateau_claim_allowed'        => $plateauClaimAllowed,
        ];
    }

    /**
     * Derive next leverage moves across context, proof, task_fabric, model_amplifier, simplification.
     *
     * @param  array<array<string,mixed>>  $recentTasks
     * @param  array<array<string,mixed>>  $eligibleJumps
     * @param  array<array<string,mixed>>  $rejectedJumps
     * @return array<string,mixed>
     */
    private function deriveNextLeverageMoves(array $recentTasks, array $eligibleJumps, array $rejectedJumps): array
    {
        $moves = [];

        // context: if structural unlock rate is low, propose context expansion
        $unlockCount = 0;
        $totalCount = count($recentTasks);
        foreach ($recentTasks as $task) {
            if ((bool) ($task['unlocks_new_capability'] ?? false)) {
                $unlockCount++;
            }
        }
        $structuralUnlockRate = $totalCount > 0 ? $unlockCount / $totalCount : 1.0;

        if ($structuralUnlockRate < 0.5) {
            $moves['context'] = [
                'move' => 'expand_context_window',
                'reason' => 'structural_unlock_rate_below_threshold',
                'expected_delta' => sprintf('unlock_rate_from_%.2f_to_%.2f', $structuralUnlockRate, min(1.0, $structuralUnlockRate + 0.3)),
            ];
        }

        // proof: if rejected jumps cite missing proof gates, propose proof infrastructure
        foreach ($rejectedJumps as $jump) {
            if (in_array('no_proof_gates_defined', $jump['reasons'] ?? [], true)) {
                $moves['proof'] = [
                    'move' => 'build_proof_infrastructure',
                    'reason' => 'rejected_jumps_lack_proof_gates',
                    'expected_delta' => 'enable_proof_gated_capability_jumps',
                ];
                break;
            }
        }

        // task_fabric: if repeated family rate is high, propose task fabric diversification
        $familyCounts = [];
        foreach ($recentTasks as $task) {
            $family = (string) ($task['task_family'] ?? '');
            if ($family !== '') {
                $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            }
        }
        $familyTaskCount = array_sum($familyCounts);
        if ($familyTaskCount > 0) {
            $repeatedFamilyRate = max($familyCounts) / $familyTaskCount;
            if ($repeatedFamilyRate > 0.5) {
                $moves['task_fabric'] = [
                    'move' => 'diversify_task_fabric',
                    'reason' => 'high_repeated_family_rate',
                    'expected_delta' => sprintf('reduce_family_concentration_from_%.2f', $repeatedFamilyRate),
                ];
            }
        }

        // model_amplifier: if eligible jumps exist, propose model amplifier
        if (count($eligibleJumps) > 0) {
            $moves['model_amplifier'] = [
                'move' => 'deploy_model_amplifier',
                'reason' => 'eligible_capability_jumps_available',
                'expected_delta' => 'unlock_new_capability_surface',
            ];
        }

        // simplification: if saturation score is high, propose simplification
        if ($familyTaskCount > 0) {
            $saturationScore = round(($repeatedFamilyRate + (1.0 - $structuralUnlockRate)) / 2.0, 4);
            if ($saturationScore >= self::HIGH_SATURATION_THRESHOLD) {
                $moves['simplification'] = [
                    'move' => 'simplify_existing_complexity',
                    'reason' => 'high_saturation_score',
                    'expected_delta' => sprintf('reduce_saturation_from_%.2f', $saturationScore),
                ];
            }
        }

        return $moves;
    }
}
