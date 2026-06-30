<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Estimates whether another frontier harvest pass is still yielding
 * genuinely new structural tasks, or is burning tokens on diminishing
 * returns — from recent harvest wave facts, never from raw seed volume
 * alone.
 *
 * Computed per frontier:
 *   marginal_yield            = unique_high_value_count / raw_seed_count
 *                                (the fraction of raw seeds that turned into
 *                                NEW, high-value, non-duplicate tasks — a raw
 *                                seed count by itself proves nothing).
 *   duplicate_rate             = duplicate_count / raw_seed_count
 *   forbidden_wall_rate        = forbidden_wall_count / raw_seed_count
 *                                (seeds that hit a pétreo/forbidden boundary
 *                                and could never become a task)
 *   expected_next_batch_value  = unique_high_value_count × compounding_impact_per_task
 *                                (defaults compounding_impact_per_task to 1.0)
 *
 * DECISION PRIORITY (first match wins):
 *   change_strategy        — forbidden_wall_rate >= forbidden_wall_rate_ceiling:
 *                             the current harvest method keeps hitting a
 *                             structural wall; no amount of volume fixes that.
 *   consolidate             — duplicate_rate >= duplicate_rate_ceiling:
 *                             most seeds already exist; harvesting more raw
 *                             volume just re-discovers the same ground —
 *                             consolidate/dedup before harvesting again.
 *   continue                — marginal_yield >= marginal_yield_floor AND
 *                             expected_next_batch_value >= min_expected_batch_value:
 *                             the frontier is still producing real, valuable,
 *                             novel structural tasks.
 *   stop_frontier_harvest   — none of the above: yield has dried up. This is
 *                             also the forced outcome whenever
 *                             unique_high_value_count = 0, no matter how
 *                             large raw_seed_count is — raw seed count is
 *                             NEVER, by itself, evidence of remaining value.
 *
 * INPUT:
 *   frontiers: list<{
 *     frontier_id:                  string
 *     raw_seed_count:                int
 *     unique_high_value_count?:      int   (default 0)
 *     duplicate_count?:              int   (default 0)
 *     forbidden_wall_count?:         int   (default 0)
 *     compounding_impact_per_task?:  float (default 1.0)
 *   }>
 *   marginal_yield_floor?:           float (default 0.15)
 *   duplicate_rate_ceiling?:         float (default 0.50)
 *   forbidden_wall_rate_ceiling?:    float (default 0.30)
 *   min_expected_batch_value?:       float (default 1.0)
 *
 * OUTPUT:
 *   { schema, results }
 *
 *   results: list<{
 *     frontier_id, decision, marginal_yield, duplicate_rate,
 *     forbidden_wall_rate, expected_next_batch_value, evidence_counts, reasons
 *   }>
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainFrontierHarvestYieldModel
{
    public const SCHEMA = 'atlas.external_brain.frontier_harvest_yield_model.v1';

    public const DECISION_CONTINUE = 'continue';
    public const DECISION_CHANGE_STRATEGY = 'change_strategy';
    public const DECISION_CONSOLIDATE = 'consolidate';
    public const DECISION_STOP_FRONTIER_HARVEST = 'stop_frontier_harvest';

    private const DEFAULT_MARGINAL_YIELD_FLOOR = 0.15;
    private const DEFAULT_DUPLICATE_RATE_CEILING = 0.50;
    private const DEFAULT_FORBIDDEN_WALL_RATE_CEILING = 0.30;
    private const DEFAULT_MIN_EXPECTED_BATCH_VALUE = 1.0;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function model(array $input): array
    {
        $frontiers = is_array($input['frontiers'] ?? null) ? $input['frontiers'] : [];
        $marginalYieldFloor = (float) ($input['marginal_yield_floor'] ?? self::DEFAULT_MARGINAL_YIELD_FLOOR);
        $duplicateRateCeiling = (float) ($input['duplicate_rate_ceiling'] ?? self::DEFAULT_DUPLICATE_RATE_CEILING);
        $forbiddenWallRateCeiling = (float) ($input['forbidden_wall_rate_ceiling'] ?? self::DEFAULT_FORBIDDEN_WALL_RATE_CEILING);
        $minExpectedBatchValue = (float) ($input['min_expected_batch_value'] ?? self::DEFAULT_MIN_EXPECTED_BATCH_VALUE);

        $results = [];

        foreach ($frontiers as $f) {
            if (! is_array($f) || ! isset($f['frontier_id'])) {
                continue;
            }

            $frontierId = (string) $f['frontier_id'];
            $rawSeedCount = max(0, (int) ($f['raw_seed_count'] ?? 0));
            $uniqueHighValueCount = max(0, (int) ($f['unique_high_value_count'] ?? 0));
            $duplicateCount = max(0, (int) ($f['duplicate_count'] ?? 0));
            $forbiddenWallCount = max(0, (int) ($f['forbidden_wall_count'] ?? 0));
            $compoundingImpactPerTask = (float) ($f['compounding_impact_per_task'] ?? 1.0);

            $denominator = max(1, $rawSeedCount);
            $marginalYield = round($uniqueHighValueCount / $denominator, 4);
            $duplicateRate = round($duplicateCount / $denominator, 4);
            $forbiddenWallRate = round($forbiddenWallCount / $denominator, 4);
            $expectedNextBatchValue = round($uniqueHighValueCount * $compoundingImpactPerTask, 4);

            $reasons = [];
            if ($uniqueHighValueCount === 0 && $rawSeedCount > 0) {
                $reasons[] = sprintf('raw_seed_count=%d alone is not evidence; unique_high_value_count=0', $rawSeedCount);
            }

            $isHighForbiddenWall = $forbiddenWallRate >= $forbiddenWallRateCeiling;
            $isHighDuplicate = $duplicateRate >= $duplicateRateCeiling;
            $isHighYield = $marginalYield >= $marginalYieldFloor && $expectedNextBatchValue >= $minExpectedBatchValue;

            if ($isHighForbiddenWall) {
                $decision = self::DECISION_CHANGE_STRATEGY;
                $reasons[] = sprintf('forbidden_wall_rate=%.2f >= ceiling=%.2f', $forbiddenWallRate, $forbiddenWallRateCeiling);
            } elseif ($isHighDuplicate) {
                $decision = self::DECISION_CONSOLIDATE;
                $reasons[] = sprintf('duplicate_rate=%.2f >= ceiling=%.2f', $duplicateRate, $duplicateRateCeiling);
            } elseif ($isHighYield) {
                $decision = self::DECISION_CONTINUE;
                $reasons[] = sprintf('marginal_yield=%.2f >= floor=%.2f', $marginalYield, $marginalYieldFloor);
                $reasons[] = sprintf('expected_next_batch_value=%.2f >= min=%.2f', $expectedNextBatchValue, $minExpectedBatchValue);
            } else {
                $decision = self::DECISION_STOP_FRONTIER_HARVEST;
                if ($marginalYield < $marginalYieldFloor) {
                    $reasons[] = sprintf('marginal_yield=%.2f < floor=%.2f', $marginalYield, $marginalYieldFloor);
                }
                if ($expectedNextBatchValue < $minExpectedBatchValue) {
                    $reasons[] = sprintf('expected_next_batch_value=%.2f < min=%.2f', $expectedNextBatchValue, $minExpectedBatchValue);
                }
            }

            $results[] = [
                'frontier_id' => $frontierId,
                'decision' => $decision,
                'marginal_yield' => $marginalYield,
                'duplicate_rate' => $duplicateRate,
                'forbidden_wall_rate' => $forbiddenWallRate,
                'expected_next_batch_value' => $expectedNextBatchValue,
                'evidence_counts' => [
                    'raw_seed_count' => $rawSeedCount,
                    'unique_high_value_count' => $uniqueHighValueCount,
                    'duplicate_count' => $duplicateCount,
                    'forbidden_wall_count' => $forbiddenWallCount,
                ],
                'reasons' => $reasons,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'results' => $results,
        ];
    }
}
