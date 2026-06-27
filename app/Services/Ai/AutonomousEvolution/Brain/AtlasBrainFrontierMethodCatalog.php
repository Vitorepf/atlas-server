<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * FRONTIER METHOD CATALOG — frontier-harvest organ. Hard-coded list of known brain methods (from
 * docs/brain-self-improvement-method-catalog.md) with implementation status: which organ class
 * implements each, which remain unharvested. Returns the unharvested set so an operator-facing
 * surface can show "what frontier ideas the brain has not yet built into itself".
 *
 * Pure const + class_exists check. Pétreo: réu would mark unbuilt methods as built to silence
 * the gap.
 */
final class AtlasBrainFrontierMethodCatalog
{
    public const SCHEMA = 'atlas.brain.frontier_method_catalog.v1';

    /** @var list<array{id:string, summary:string, organ_class:?string}> */
    private const METHODS = [
        ['id' => 'path_yield_momentum', 'summary' => 'earlier vs later half yield per path, trend label', 'organ_class' => AtlasBrainPathYieldMomentum::class],
        ['id' => 'path_diversity_score', 'summary' => 'shannon entropy of path distribution; mode-collapse KPI', 'organ_class' => AtlasBrainPathDiversityScore::class],
        ['id' => 'path_oscillation_detector', 'summary' => 'detects ABABAB ping-pong starving 5 other paths', 'organ_class' => AtlasBrainPathOscillationDetector::class],
        ['id' => 'plan_adviser_red_team', 'summary' => 'vetoes low-yield path picks with alternative_path', 'organ_class' => AtlasBrainPlanAdviserRedTeam::class],
        ['id' => 'next_cycle_projector', 'summary' => 'fuses adviser + red-team + momentum into projected_path', 'organ_class' => AtlasBrainNextCycleProjector::class],
        ['id' => 'scope_flag_auditor', 'summary' => 'flags inconsistent config flag combinations', 'organ_class' => AtlasBrainScopeFlagAuditor::class],
        // — unharvested frontier methods (organ_class=null) —
        ['id' => 'reflection_provenance_chain', 'summary' => 'per-reflection lineage of which prior cycles influenced it', 'organ_class' => null],
        ['id' => 'cross_scope_pattern_xref', 'summary' => 'detect patterns common to >=2 scopes (transferable lessons)', 'organ_class' => null],
        ['id' => 'gate_false_positive_estimator', 'summary' => 'estimate gate FP rate by sampling refused-but-author-believes-real', 'organ_class' => null],
        ['id' => 'projection_calibration_score', 'summary' => 'compare past projector outputs vs actual next-cycle picks', 'organ_class' => null],
        ['id' => 'hint_to_path_drift_alarm', 'summary' => 'alarm when hint frequency drifts >2σ from rolling baseline', 'organ_class' => null],
        ['id' => 'compounding_velocity', 'summary' => 'd(yield)/d(cycle) per path; rate of improvement, not level', 'organ_class' => null],
    ];

    /**
     * @return array{schema:string, total:int, implemented:int, unharvested:int, methods:list<array{id:string, summary:string, organ_class:?string, status:string}>}
     */
    public function inspect(): array
    {
        $methods = [];
        $implemented = 0;
        foreach (self::METHODS as $m) {
            $status = $m['organ_class'] !== null && class_exists($m['organ_class']) ? 'implemented' : 'unharvested';
            if ($status === 'implemented') {
                $implemented++;
            }
            $methods[] = $m + ['status' => $status];
        }

        return [
            'schema' => self::SCHEMA,
            'total' => count($methods),
            'implemented' => $implemented,
            'unharvested' => count($methods) - $implemented,
            'methods' => $methods,
        ];
    }
}
