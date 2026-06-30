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
