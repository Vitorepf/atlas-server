<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure harness: scores small-model, scaffolded-small-model, and frontier-model
 * proposals across five dimensions on a pre-computed historical benchmark set.
 * No live providers are called — operates entirely on supplied scores.
 *
 * Dimensions (each 0–10):
 *   implementability, non_duplication, structural_leverage,
 *   evidence_strength, anti_goodhart_resistance
 *
 * tier_scores: per-tier average across all challenges.
 * lift_from_scaffold: scaffolded_small avg − small_model avg.
 * frontier_multiplier: frontier avg ÷ small_model avg (null when small avg = 0).
 * failing_dimensions: scaffolded_small per-dimension avg below ACCEPTABLE_FLOOR (7.0).
 * next_scaffold_improvement: failing dimension with the lowest scaffolded_small avg.
 */
final class AtlasExternalBrainFrontierLiftBenchmarkHarness
{
    public const SCHEMA = 'atlas.external_brain.frontier_lift_benchmark_harness.v1';

    public const DIMENSIONS = [
        'implementability',
        'non_duplication',
        'structural_leverage',
        'evidence_strength',
        'anti_goodhart_resistance',
    ];

    public const ACCEPTABLE_FLOOR = 7.0;

    private const TIERS = ['small_model', 'scaffolded_small', 'frontier'];

    /**
     * @param  array<string,mixed>  $input  benchmark_challenges list
     * @return array<string,mixed>
     */
    public function measure(array $input): array
    {
        $challenges = is_array($input['benchmark_challenges'] ?? null) ? $input['benchmark_challenges'] : [];
        $n = count($challenges);

        if ($n === 0) {
            return [
                'schema_version' => self::SCHEMA,
                'tier_scores' => [],
                'lift_summary' => ['lift_from_scaffold' => 0.0, 'frontier_multiplier' => null],
                'failing_dimensions' => [],
                'next_scaffold_improvement' => null,
            ];
        }

        $tierTotals = array_fill_keys(self::TIERS, 0.0);
        $dimTotals = [];
        foreach (self::TIERS as $tier) {
            $dimTotals[$tier] = array_fill_keys(self::DIMENSIONS, 0.0);
        }

        foreach ($challenges as $challenge) {
            foreach (self::TIERS as $tier) {
                $data = is_array($challenge[$tier] ?? null) ? $challenge[$tier] : [];
                $dimSum = 0.0;
                foreach (self::DIMENSIONS as $dim) {
                    $score = (float) ($data[$dim] ?? 0);
                    $dimTotals[$tier][$dim] += $score;
                    $dimSum += $score;
                }
                $tierTotals[$tier] += $dimSum / count(self::DIMENSIONS);
            }
        }

        $tierAvgs = [];
        foreach (self::TIERS as $tier) {
            $tierAvgs[$tier] = round($tierTotals[$tier] / $n, 3);
        }

        $failingDimensions = [];
        $worstDim = null;
        $worstScore = PHP_FLOAT_MAX;

        foreach (self::DIMENSIONS as $dim) {
            $scaffoldedAvg = round($dimTotals['scaffolded_small'][$dim] / $n, 3);
            if ($scaffoldedAvg < self::ACCEPTABLE_FLOOR) {
                $failingDimensions[] = [
                    'dimension' => $dim,
                    'scaffolded_avg' => $scaffoldedAvg,
                    'small_avg' => round($dimTotals['small_model'][$dim] / $n, 3),
                ];
                if ($scaffoldedAvg < $worstScore) {
                    $worstScore = $scaffoldedAvg;
                    $worstDim = $dim;
                }
            }
        }

        $liftFromScaffold = round($tierAvgs['scaffolded_small'] - $tierAvgs['small_model'], 3);
        $frontierMultiplier = $tierAvgs['small_model'] > 0.0
            ? round($tierAvgs['frontier'] / $tierAvgs['small_model'], 3)
            : null;

        return [
            'schema_version' => self::SCHEMA,
            'tier_scores' => $tierAvgs,
            'lift_summary' => [
                'lift_from_scaffold' => $liftFromScaffold,
                'frontier_multiplier' => $frontierMultiplier,
            ],
            'failing_dimensions' => $failingDimensions,
            'next_scaffold_improvement' => $worstDim,
        ];
    }
}
