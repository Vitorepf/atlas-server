<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure circuit breaker. Inspects five self-construction sprawl signals and
 * decides whether the brain should consolidate before adding more organs.
 *
 * Decision: consolidate_first | continue_building
 *
 * consolidate_first fires when:
 *   A. overlap_score >= OVERLAP_THRESHOLD AND class_growth_count >= GROWTH_THRESHOLD
 *   OR
 *   B. two or more secondary signals (duplicate_capabilities, shallow_scaffold, debt)
 *      each exceed their own threshold
 *
 * Consolidation actions returned under consolidate_first are drawn from the
 * signalling dimensions: merge (overlap/dup), retire (scaffold), simplify (debt, growth).
 * No feature recommendations are ever included in consolidation_actions.
 *
 * continue_building always reports simplification_risk and capability_gaps.
 *
 * Simplification risk (0.0–1.0, rounded to 4dp):
 *   overlap × 0.30 + growth_ratio × 0.20 + dup_ratio × 0.20
 *   + scaffold × 0.15 + debt_ratio × 0.15
 *
 * Pure: no I/O, no providers, deterministic.
 */
final class AtlasExternalBrainConsolidationFirstCircuitBreaker
{
    public const SCHEMA = 'atlas.external_brain.consolidation_first_circuit_breaker.v1';

    public const DECISION_CONSOLIDATE = 'consolidate_first';
    public const DECISION_CONTINUE    = 'continue_building';

    private const OVERLAP_THRESHOLD   = 0.4;
    private const GROWTH_THRESHOLD    = 30;
    private const DUPLICATE_THRESHOLD = 3;
    private const SCAFFOLD_THRESHOLD  = 0.3;
    private const DEBT_THRESHOLD      = 5;

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    public function evaluate(array $metrics): array
    {
        $overlapScore  = (float) ($metrics['overlap_score']                    ?? 0.0);
        $classGrowth   = (int)   ($metrics['class_growth_count']               ?? 0);
        $dupNames      = array_values((array) ($metrics['duplicate_capability_names'] ?? []));
        $scaffoldRatio = (float) ($metrics['shallow_scaffold_ratio']           ?? 0.0);
        $debt          = (int)   ($metrics['unresolved_simplification_debt']   ?? 0);
        $capGaps       = (int)   ($metrics['capability_gap_count']             ?? 0);

        $overlapThreshold   = (float) ($metrics['overlap_threshold']    ?? self::OVERLAP_THRESHOLD);
        $growthThreshold    = (int)   ($metrics['growth_threshold']     ?? self::GROWTH_THRESHOLD);
        $duplicateThreshold = (int)   ($metrics['duplicate_threshold']  ?? self::DUPLICATE_THRESHOLD);
        $scaffoldThreshold  = (float) ($metrics['scaffold_threshold']   ?? self::SCAFFOLD_THRESHOLD);
        $debtThreshold      = (int)   ($metrics['debt_threshold']       ?? self::DEBT_THRESHOLD);

        $overlapHigh   = $overlapScore     >= $overlapThreshold;
        $growthHigh    = $classGrowth      >= $growthThreshold;
        $dupHigh       = count($dupNames)  >= $duplicateThreshold;
        $scaffoldHigh  = $scaffoldRatio    >= $scaffoldThreshold;
        $debtHigh      = $debt             >= $debtThreshold;

        $secondaryCount  = (int) $dupHigh + (int) $scaffoldHigh + (int) $debtHigh;
        $shouldConsolidate = ($overlapHigh && $growthHigh) || $secondaryCount >= 2;

        $risk = $this->computeRisk($overlapScore, $classGrowth, $growthThreshold, count($dupNames), $scaffoldRatio, $debt);

        if ($shouldConsolidate) {
            return [
                'schema_version'        => self::SCHEMA,
                'decision'              => self::DECISION_CONSOLIDATE,
                'consolidation_actions' => $this->buildActions($overlapHigh, $dupHigh, $scaffoldHigh, $debtHigh, $growthHigh),
                'simplification_risk'   => $risk,
                'triggers'              => array_values(array_filter([
                    $overlapHigh  ? 'overlap_exceeded'       : null,
                    $growthHigh   ? 'class_growth_exceeded'  : null,
                    $dupHigh      ? 'duplicate_capabilities' : null,
                    $scaffoldHigh ? 'shallow_scaffold'       : null,
                    $debtHigh     ? 'unresolved_debt'        : null,
                ])),
            ];
        }

        return [
            'schema_version'      => self::SCHEMA,
            'decision'            => self::DECISION_CONTINUE,
            'capability_gaps'     => $capGaps,
            'simplification_risk' => $risk,
            'triggers'            => [],
        ];
    }

    /** @return list<array{action:string,reason:string}> */
    private function buildActions(bool $overlap, bool $dup, bool $scaffold, bool $debt, bool $growth): array
    {
        $actions = [];

        if ($overlap || $dup) {
            $actions[] = [
                'action' => 'merge',
                'reason' => $overlap ? 'high_overlap_score' : 'duplicate_capability_names',
            ];
        }
        if ($scaffold) {
            $actions[] = ['action' => 'retire', 'reason' => 'shallow_scaffold_excess'];
        }
        if ($debt || $growth) {
            $actions[] = ['action' => 'simplify', 'reason' => $debt ? 'unresolved_simplification_debt' : 'class_growth_excess'];
        }

        // Guarantee at least one action.
        if ($actions === []) {
            $actions[] = ['action' => 'simplify', 'reason' => 'general_sprawl'];
        }

        return $actions;
    }

    private function computeRisk(
        float $overlap, int $growth, int $growthThreshold,
        int $dupCount, float $scaffold, int $debt,
    ): float {
        $growthCap = $growthThreshold * 2;
        $growthRatio = $growthCap > 0 ? min(1.0, $growth / $growthCap) : 0.0;
        $dupRatio    = min(1.0, $dupCount / 10);
        $debtRatio   = min(1.0, $debt / 10);

        $risk = $overlap  * 0.30
              + $growthRatio * 0.20
              + $dupRatio   * 0.20
              + $scaffold   * 0.15
              + $debtRatio  * 0.15;

        return round(min(1.0, $risk), 4);
    }
}
