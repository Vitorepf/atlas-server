<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStarvationDetector;

/**
 * Pure planner. Converts starved brain paths into a balanced next-wave rotation
 * plan instead of endless same-surface bug hunting.
 *
 * INVARIANTS:
 *  - gate_regression_active=true → mode='gate_regression_priority'; the rotation
 *    plan is still produced for the NEXT healthy cycle, but the current recommendation
 *    is to repair the gate first (gate repair is a higher priority than rotation).
 *  - gate_regression_active=false → mode='path_rotation'; the rotation plan is live.
 *  - The rotation plan covers ≥3 distinct path families when ≥3 starved paths exist.
 *  - Paths are prioritised in canonical order (frontier-harvest first); starved paths
 *    always appear before hit paths in the plan.
 *
 * Canonical paths (from AtlasBrainPathStarvationDetector::CANONICAL_PATHS):
 *   frontier-harvest, metrics-optimization, pattern-design, simulation-twin,
 *   comprehension-deepening, adversarial-critique, compounding
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPathStarvationUnblockPlanner
{
    public const SCHEMA = 'atlas.external_brain.path_starvation_unblock_planner.v1';

    public const MODE_GATE_REGRESSION_PRIORITY = 'gate_regression_priority';
    public const MODE_PATH_ROTATION            = 'path_rotation';

    private const TASK_SHAPES = [
        'frontier-harvest'       => 'research:harvest_frontier_papers_and_tools',
        'metrics-optimization'   => 'metrics:measure_and_optimize_key_performance_indicator',
        'pattern-design'         => 'design:identify_and_formalize_reusable_pattern',
        'simulation-twin'        => 'simulation:build_scenario_twin_for_edge_case_coverage',
        'comprehension-deepening' => 'comprehension:deep_read_and_model_subsystem_understanding',
        'adversarial-critique'   => 'critique:adversarial_review_of_spec_or_gate_boundary',
        'compounding'            => 'compounding:wire_proven_organ_into_live_pipeline',
    ];

    private const RATIONALES = [
        'frontier-harvest'       => 'no_recent_external_signal_harvest_risks_missing_capability_leap',
        'metrics-optimization'   => 'no_recent_measurement_leaves_perf_regression_invisible',
        'pattern-design'         => 'no_recent_pattern_work_accumulates_ad_hoc_variation',
        'simulation-twin'        => 'no_recent_simulation_leaves_edge_cases_untested',
        'comprehension-deepening' => 'no_recent_deep_read_risks_model_drift_from_codebase',
        'adversarial-critique'   => 'no_recent_critique_allows_specs_to_diverge_unchallenged',
        'compounding'            => 'no_recent_wiring_leaves_proven_organs_dormant',
    ];

    /**
     * @param  array{
     *   starved_paths?: list<string>,
     *   hit_paths?: list<string>,
     *   gate_regression_active?: bool,
     *   max_wave_size?: int,
     * }  $input
     * @return array{
     *   schema:string,
     *   mode:string,
     *   rotation_plan:list<array<string,mixed>>,
     *   next_wave_paths:list<string>,
     *   starved_count:int,
     *   gate_regression_note:string|null,
     * }
     */
    public function plan(array $input): array
    {
        $starvedPaths       = $this->filterCanonical((array) ($input['starved_paths'] ?? []));
        $hitPaths           = $this->filterCanonical((array) ($input['hit_paths']     ?? []));
        $gateRegressionActive = (bool) ($input['gate_regression_active']             ?? false);
        $maxWaveSize        = max(1, (int) ($input['max_wave_size']                  ?? 3));

        // Build ordered plan: starved first (canonical order), then hit paths.
        $canonicalOrder = array_flip(AtlasBrainPathStarvationDetector::CANONICAL_PATHS);

        $orderedStarved = $starvedPaths;
        usort($orderedStarved, static fn (string $a, string $b): int => ($canonicalOrder[$a] ?? 99) <=> ($canonicalOrder[$b] ?? 99));

        $orderedHit = $hitPaths;
        usort($orderedHit, static fn (string $a, string $b): int => ($canonicalOrder[$a] ?? 99) <=> ($canonicalOrder[$b] ?? 99));

        $allOrdered = array_values(array_unique(array_merge($orderedStarved, $orderedHit)));

        $rotationPlan = [];
        foreach ($allOrdered as $priority => $path) {
            $isStarved = in_array($path, $starvedPaths, true);
            $rotationPlan[] = [
                'path'       => $path,
                'priority'   => $priority + 1,
                'starved'    => $isStarved,
                'task_shape' => self::TASK_SHAPES[$path] ?? 'generic:address_'.$path,
                'rationale'  => $isStarved ? (self::RATIONALES[$path] ?? 'path_not_covered_recently') : 'maintaining_coverage',
            ];
        }

        $nextWavePaths = array_slice($orderedStarved, 0, $maxWaveSize);

        $mode = $gateRegressionActive
            ? self::MODE_GATE_REGRESSION_PRIORITY
            : self::MODE_PATH_ROTATION;

        $gateNote = $gateRegressionActive
            ? 'gate_regression_must_be_repaired_before_rotation_activates_rotation_plan_ready_for_next_healthy_cycle'
            : null;

        return [
            'schema'                => self::SCHEMA,
            'mode'                  => $mode,
            'rotation_plan'         => $rotationPlan,
            'next_wave_paths'       => $nextWavePaths,
            'starved_count'         => count($starvedPaths),
            'gate_regression_note'  => $gateNote,
        ];
    }

    private const DEFAULT_STARVATION_CYCLES = 5;

    private const DEFAULT_NON_SELECTION_THRESHOLD = 3;

    private const DEFAULT_LOW_VALUE_THRESHOLD = 0.3;

    private const DEFAULT_EVIDENCE_COVERAGE_FLOOR = 0.5;

    /**
     * Detects path starvation per lane from last_seen, value potential,
     * blocked dependencies and repeated non-selection, and recommends
     * unblock, defer_with_reason or retire_lane for each neglected lane.
     *
     * A lane is starved when last_seen_cycles_ago >= starvation_threshold_cycles
     * OR non_selection_count >= non_selection_threshold. Non-starved lanes are
     * not returned — they need no unblock plan.
     *
     * RECOMMENDATION (first matching rule wins):
     *   value_potential < low_value_threshold        -> retire_lane
     *     (age alone never promotes a low-value lane — this check runs first)
     *   blocked_dependencies is non-empty             -> defer_with_reason
     *   otherwise                                      -> unblock
     *
     * @param  array<string,mixed>  $input  { lanes: list<{lane_id, last_seen_cycles_ago?,
     *   value_potential?, blocked_dependencies?, non_selection_count?}>,
     *   starvation_threshold_cycles?, non_selection_threshold?, low_value_threshold? }
     * @return array<string,mixed>
     */
    public function recommendNeglectedLanes(array $input): array
    {
        $lanes = is_array($input['lanes'] ?? null) ? $input['lanes'] : [];
        $starvationThresholdCycles = max(1, (int) ($input['starvation_threshold_cycles'] ?? self::DEFAULT_STARVATION_CYCLES));
        $nonSelectionThreshold = max(1, (int) ($input['non_selection_threshold'] ?? self::DEFAULT_NON_SELECTION_THRESHOLD));
        $lowValueThreshold = max(0.0, min(1.0, (float) ($input['low_value_threshold'] ?? self::DEFAULT_LOW_VALUE_THRESHOLD)));
        $evidenceCoverageFloor = max(0.0, min(1.0, (float) ($input['evidence_coverage_floor'] ?? self::DEFAULT_EVIDENCE_COVERAGE_FLOOR)));

        $recommendations = [];
        foreach ($lanes as $lane) {
            if (! is_array($lane) || ! isset($lane['lane_id'])) {
                continue;
            }

            $laneId = (string) $lane['lane_id'];
            $lastSeenCyclesAgo = max(0, (int) ($lane['last_seen_cycles_ago'] ?? 0));
            $valuePotential = max(0.0, min(1.0, (float) ($lane['value_potential'] ?? 0.0)));
            $blockedDependencies = is_array($lane['blocked_dependencies'] ?? null) ? array_values($lane['blocked_dependencies']) : [];
            $nonSelectionCount = max(0, (int) ($lane['non_selection_count'] ?? 0));
            $evidenceCoverage = array_key_exists('evidence_coverage', $lane)
                ? max(0.0, min(1.0, (float) $lane['evidence_coverage']))
                : 1.0;

            $starved = $lastSeenCyclesAgo >= $starvationThresholdCycles || $nonSelectionCount >= $nonSelectionThreshold;
            if (! $starved) {
                continue;
            }

            [$recommendation, $reason] = match (true) {
                $valuePotential < $lowValueThreshold => ['retire_lane', 'low_value_potential_age_alone_does_not_justify_promotion'],
                $blockedDependencies !== [] => ['defer_with_reason', 'blocked_by_dependencies: '.implode(',', $blockedDependencies)],
                $evidenceCoverage < $evidenceCoverageFloor => ['defer_with_reason', "evidence_coverage={$evidenceCoverage}_below_floor={$evidenceCoverageFloor}"],
                default => ['unblock', 'starved_lane_with_sufficient_value_and_no_blockers'],
            };

            $nextTaskShape = match ($recommendation) {
                'unblock' => 'unblock_lane_'.$laneId,
                'defer_with_reason' => 'resolve_dependencies_for_'.$laneId,
                'retire_lane' => 'retire_lane_'.$laneId,
                default => 'investigate_'.$laneId,
            };

            $requiredEvidence = match ($recommendation) {
                'unblock' => ['lane_value_proof', 'no_blocker_confirmed'],
                'defer_with_reason' => ['dependency_resolved', 'evidence_coverage_met'],
                'retire_lane' => ['low_value_confirmed'],
                default => ['lane_assessment'],
            };

            $expectedUnlock = match ($recommendation) {
                'unblock' => 'lane_'.$laneId.'_becomes_claimable',
                'defer_with_reason' => 'lane_'.$laneId.'_unblocked_after_dependencies',
                'retire_lane' => 'lane_'.$laneId.'_removed_from_rotation',
                default => 'lane_'.$laneId.'_status_clarified',
            };

            $recommendations[] = [
                'lane_id' => $laneId,
                'starved' => true,
                'last_seen_cycles_ago' => $lastSeenCyclesAgo,
                'value_potential' => $valuePotential,
                'blocked_dependencies' => $blockedDependencies,
                'non_selection_count' => $nonSelectionCount,
                'evidence_coverage' => $evidenceCoverage,
                'recommendation' => $recommendation,
                'reason' => $reason,
                'next_task_shape' => $nextTaskShape,
                'required_evidence' => $requiredEvidence,
                'expected_unlock' => $expectedUnlock,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
            'neglected_lane_count' => count($recommendations),
        ];
    }

    /** @return list<string> */
    private function filterCanonical(array $paths): array
    {
        $canonical = AtlasBrainPathStarvationDetector::CANONICAL_PATHS;

        return array_values(array_filter(
            array_map('strval', $paths),
            static fn (string $p): bool => in_array($p, $canonical, true),
        ));
    }
}
