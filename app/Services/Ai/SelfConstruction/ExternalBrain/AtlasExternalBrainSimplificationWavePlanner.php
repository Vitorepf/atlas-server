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
 * RANKING (within eligible candidates, best-first): dependency_risk ASC (low first),
 * line_reduction DESC, rollback_ease ASC (easy first), candidate_id ASC (tiebreak).
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

            $missing = [];
            if (! $hasCoverage) {
                $missing[] = 'add_test_coverage';
            }
            if (! $ownershipClear) {
                $missing[] = 'clarify_ownership';
            }

            if ($missing !== []) {
                $deferred[] = ['candidate_id' => $id, 'required_prework' => $missing];

                continue;
            }

            $eligible[] = [
                'candidate_id' => $id,
                'dependency_risk' => (string) ($c['dependency_risk'] ?? 'high'),
                'line_reduction' => (int) ($c['line_reduction'] ?? 0),
                'rollback_ease' => (string) ($c['rollback_ease'] ?? 'hard'),
            ];
        }

        usort($eligible, static function (array $a, array $b): int {
            $ra = self::RISK_RANK[$a['dependency_risk']] ?? 99;
            $rb = self::RISK_RANK[$b['dependency_risk']] ?? 99;

            return $ra <=> $rb
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

        $safetyNotes = [
            sprintf('eligibility requires has_behavior_coverage=true and ownership_clear=true; %d candidate(s) deferred for prework', count($deferred)),
            sprintf('wave capacity=%d (base=%d, urgent=%s)', $effectiveCapacity, $baseCapacity, $urgent ? 'true' : 'false'),
        ];
        if ($urgent) {
            $safetyNotes[] = 'build_or_repair_urgent=true: simplification capacity halved so it never crowds out urgent work';
        }

        return [
            'schema' => self::SCHEMA,
            'waves' => $waves,
            'deferred' => $deferred,
            'capacity_allocation' => [
                'base_wave_capacity' => $baseCapacity,
                'effective_wave_capacity' => $effectiveCapacity,
                'build_or_repair_urgent' => $urgent,
                'eligible_count' => count($eligible),
                'wave_count' => count($waves),
            ],
            'expected_reduction_score' => $expectedReductionScore,
            'safety_notes' => $safetyNotes,
        ];
    }
}
