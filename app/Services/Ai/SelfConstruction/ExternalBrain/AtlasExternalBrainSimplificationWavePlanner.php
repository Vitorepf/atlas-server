<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure simplification-wave planner — groups SAFE simplification candidates into waves that reduce code
 * and cognitive load WITHOUT breaking behavior. Reserves capacity so simplification stays a recurring
 * lane instead of an afterthought, and never lets it crowd out urgent build/repair work.
 *
 * INPUT per candidate:
 *   { candidate_id, has_behavior_coverage:bool, dependency_risk:'low'|'medium'|'high',
 *     line_reduction:int, ownership_clear:bool, rollback_ease:'easy'|'moderate'|'hard' }
 *
 * INPUT capacity facts:
 *   { wave_capacity?:int (default 5), build_or_repair_urgent?:bool }
 *
 * ELIGIBILITY: a candidate enters a wave ONLY when has_behavior_coverage===true AND
 * ownership_clear===true. Anything else is DEFERRED with required_prework naming what's missing —
 * missing behavior coverage or unclear ownership are unsafe to simplify around.
 *
 * RANKING (within eligible candidates, best-first): behavior_parity_proof && consumer_impact_safe
 * FIRST (explicit safety proof beats raw deletion size), then deletion-first/consolidates_circuit,
 * then dependency_risk ASC (low first), line_reduction DESC, rollback_ease ASC (easy first),
 * candidate_id ASC (tiebreak).
 *
 * CAPACITY: when build_or_repair_urgent is true, the reserved per-wave capacity is HALVED (floor, min 1)
 * so simplification never consumes the whole capacity while build/repair is urgent. Eligible candidates
 * beyond capacity spill into additional waves (still bounded — never unlimited deletion).
 *
 * OUTPUT:
 *   { schema, waves:list<list<string>>, deferred:list<{candidate_id,required_prework:list<string>}>,
 *     capacity_allocation:array, expected_reduction_score:int, safety_notes:list<string> }
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainSimplificationWavePlanner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_wave_planner.v1';

    private const DEFAULT_WAVE_CAPACITY = 5;

    private const DEFAULT_WORKER_FLOOR = 2.0;

    private const RISK_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    private const ROLLBACK_RANK = ['easy' => 0, 'moderate' => 1, 'hard' => 2];

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $capacityFacts
     * @return array<string,mixed>
     */
    public function plan(array $candidates, array $capacityFacts = []): array
    {
        $baseCapacity = max(1, (int) ($capacityFacts['wave_capacity'] ?? self::DEFAULT_WAVE_CAPACITY));
        $urgent = (bool) ($capacityFacts['build_or_repair_urgent'] ?? false);
        $effectiveCapacity = $urgent ? max(1, (int) floor($baseCapacity / 2)) : $baseCapacity;

        $queuePressure = (string) ($capacityFacts['queue_pressure'] ?? 'normal');
        $claimablePerActiveWorker = (float) ($capacityFacts['claimable_per_active_worker'] ?? PHP_FLOAT_MAX);
        $workerFloor = (float) ($capacityFacts['worker_floor'] ?? self::DEFAULT_WORKER_FLOOR);
        $workerFloorGuarded = $queuePressure === 'low' && $claimablePerActiveWorker < $workerFloor;
        if ($workerFloorGuarded) {
            $effectiveCapacity = 1;
        }

        $eligible = [];
        $deferred = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = (string) ($c['candidate_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $hasCoverage = (bool) ($c['has_behavior_coverage'] ?? false);
            $ownershipClear = (bool) ($c['ownership_clear'] ?? false);

            // AC3: consumer impact, rollback proof, and knowledge-sync evidence default to
            // satisfied when absent (backward compatible), and only defer when a candidate
            // explicitly declares one of them unresolved.
            $consumerImpactSafe = (bool) ($c['consumer_impact_safe'] ?? true);
            $hasRollbackProof = (bool) ($c['has_rollback_proof'] ?? true);
            $hasKnowledgeSyncEvidence = (bool) ($c['has_knowledge_sync_evidence'] ?? true);

            $missing = [];
            if (! $hasCoverage) {
                $missing[] = 'add_test_coverage';
            }
            if (! $ownershipClear) {
                $missing[] = 'clarify_ownership';
            }
            if (! $consumerImpactSafe) {
                $missing[] = 'resolve_consumer_impact';
            }
            if (! $hasRollbackProof) {
                $missing[] = 'provide_rollback_proof';
            }
            if (! $hasKnowledgeSyncEvidence) {
                $missing[] = 'provide_knowledge_sync_evidence';
            }

            if ($missing !== []) {
                $deferred[] = [
                    'candidate_id' => $id,
                    'required_prework' => $missing,
                    'deferred_reason' => 'missing_required_prework',
                ];

                continue;
            }

            // AC: high-risk or hard-rollback candidates are deferred when worker capacity is low.
            $riskLevel = (string) ($c['dependency_risk'] ?? 'high');
            $rollbackEase = (string) ($c['rollback_ease'] ?? 'hard');
            $isHighRisk = $riskLevel === 'high';
            $isHardRollback = $rollbackEase === 'hard';
            $lowCapacity = $effectiveCapacity <= 1;

            if (($isHighRisk || $isHardRollback) && $lowCapacity) {
                $deferred[] = [
                    'candidate_id' => $id,
                    'required_prework' => ['wait_for_worker_capacity'],
                    'deferred_reason' => 'high_risk_or_hard_rollback_with_low_capacity',
                ];

                continue;
            }

            $eligible[] = [
                'candidate_id' => $id,
                'dependency_risk' => (string) ($c['dependency_risk'] ?? 'high'),
                'line_reduction' => (int) ($c['line_reduction'] ?? 0),
                'rollback_ease' => (string) ($c['rollback_ease'] ?? 'hard'),
                'is_deletion_first' => (bool) ($c['is_deletion_first'] ?? false),
                'consolidates_circuit' => (bool) ($c['consolidates_circuit'] ?? false),
                'behavior_parity_proof' => (bool) ($c['behavior_parity_proof'] ?? false),
                'consumer_impact_safe' => $consumerImpactSafe,
            ];
        }

        // Proof-first ordering: explicit behavior_parity_proof + consumer_impact_safe outranks
        // everything else, including raw line_reduction — safety evidence beats deletion size.
        // AC1: within that, deletion-first circuit consolidation ranks ahead of additive cleanup
        // with similar line reduction — checked before dependency_risk so it dominates ordering.
        usort($eligible, static function (array $a, array $b): int {
            $pa = ($a['behavior_parity_proof'] && $a['consumer_impact_safe']) ? 0 : 1;
            $pb = ($b['behavior_parity_proof'] && $b['consumer_impact_safe']) ? 0 : 1;

            $ca = ($a['is_deletion_first'] || $a['consolidates_circuit']) ? 0 : 1;
            $cb = ($b['is_deletion_first'] || $b['consolidates_circuit']) ? 0 : 1;

            $ra = self::RISK_RANK[$a['dependency_risk']] ?? 99;
            $rb = self::RISK_RANK[$b['dependency_risk']] ?? 99;

            return $pa <=> $pb
                ?: $ca <=> $cb
                ?: $ra <=> $rb
                ?: $b['line_reduction'] <=> $a['line_reduction']
                ?: (self::ROLLBACK_RANK[$a['rollback_ease']] ?? 99) <=> (self::ROLLBACK_RANK[$b['rollback_ease']] ?? 99)
                ?: strcmp($a['candidate_id'], $b['candidate_id']);
        });
        usort($deferred, static fn (array $a, array $b): int => strcmp($a['candidate_id'], $b['candidate_id']));

        $waves = [];
        $chunk = [];
        foreach ($eligible as $c) {
            $chunk[] = $c['candidate_id'];
            if (count($chunk) >= $effectiveCapacity) {
                $waves[] = $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $waves[] = $chunk;
        }

        $expectedReductionScore = array_sum(array_column($eligible, 'line_reduction'));
        $consolidationScore = count(array_filter(
            $eligible,
            static fn (array $c): bool => $c['is_deletion_first'] || $c['consolidates_circuit'],
        ));

        $safetyNotes = [
            sprintf('eligibility requires has_behavior_coverage=true and ownership_clear=true; %d candidate(s) deferred for prework', count($deferred)),
            sprintf('wave capacity=%d (base=%d, urgent=%s)', $effectiveCapacity, $baseCapacity, $urgent ? 'true' : 'false'),
        ];
        if ($urgent) {
            $safetyNotes[] = 'build_or_repair_urgent=true: simplification capacity halved so it never crowds out urgent work';
        }

        $complexityDebtHigh = (bool) ($capacityFacts['complexity_debt_high'] ?? false);

        $workerCapacityUsed = count($eligible);
        $rollbackBound = $workerCapacityUsed <= $effectiveCapacity;

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves,
            'deferred' => $deferred,
            'rollback_bound' => $rollbackBound,
            'worker_capacity_used' => $workerCapacityUsed,
            'capacity_allocation' => [
                'base_wave_capacity' => $baseCapacity,
                'effective_wave_capacity' => $effectiveCapacity,
                'build_or_repair_urgent' => $urgent,
                'eligible_count' => count($eligible),
                'wave_count' => count($waves),
            ],
            'worker_floor_guarded' => $workerFloorGuarded,
            'expected_reduction_score' => $expectedReductionScore,
            'consolidation_score' => $consolidationScore,
            'safety_notes' => $safetyNotes,
            'autonomy_lane_policy' => $this->autonomyLanePolicy($baseCapacity, $effectiveCapacity, $urgent),
            'stop_go_decision' => $this->stopGoDecision($urgent, $complexityDebtHigh, $eligible, $expectedReductionScore),
        ];
    }

    /**
     * Describes how simplification runs as a recurring governed lane in 24/7 autonomy — never a
     * one-off cleanup, never the whole capacity, always reserving room for urgent build/repair.
     *
     * @return array{lane:string, recurring:bool, cadence:string, min_reserved_capacity:int, crowd_out_protection_active:bool}
     */
    private function autonomyLanePolicy(int $baseCapacity, int $effectiveCapacity, bool $urgent): array
    {
        return [
            'lane' => 'simplification',
            'recurring' => true,
            'cadence' => 'every_cycle',
            'min_reserved_capacity' => min($baseCapacity, $effectiveCapacity),
            'crowd_out_protection_active' => $urgent,
        ];
    }

    /**
     * Stop/go: should this cycle PRIORITIZE simplification over new-feature origination?
     *
     * Urgent build/repair always wins (simplification yields, never blocks recovery). Otherwise,
     * high complexity debt with eligible (behavior-covered) candidates and real expected reduction
     * outranks new-feature origination — but only with evidence, never on prose alone.
     *
     * @param  list<array<string,mixed>>  $eligible
     * @return array{decision:string, reasons:list<string>}
     */
    private function stopGoDecision(bool $urgent, bool $complexityDebtHigh, array $eligible, int $expectedReductionScore): array
    {
        if ($urgent) {
            return [
                'decision' => 'defer_to_build_repair',
                'reasons' => ['build_or_repair_urgent=true: simplification yields capacity to urgent work this cycle'],
            ];
        }

        if ($complexityDebtHigh && $eligible !== [] && $expectedReductionScore > 0) {
            return [
                'decision' => 'prioritize_simplification_over_new_feature',
                'reasons' => [
                    'complexity_debt_high=true with behavior-covered eligible candidates present',
                    sprintf('eligible_count=%d, expected_reduction_score=%d', count($eligible), $expectedReductionScore),
                ],
            ];
        }

        return [
            'decision' => 'proceed_normal',
            'reasons' => ['no urgent build/repair pressure and no high-debt+evidenced simplification opportunity this cycle'],
        ];
    }
}
