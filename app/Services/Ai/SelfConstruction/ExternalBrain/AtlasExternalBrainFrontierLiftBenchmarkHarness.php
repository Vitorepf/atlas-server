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

    public const LIFT_RESULT_FRONTIER_WINS       = 'frontier_wins';
    public const LIFT_RESULT_SCAFFOLD_SUFFICIENT = 'scaffold_sufficient';
    public const LIFT_RESULT_INCONCLUSIVE        = 'inconclusive';

    /** Minimum held-out (non-training) challenges required before a lift_result can be anything but inconclusive. */
    public const MIN_HELD_OUT_SAMPLES = 5;

    /** Frontier must beat scaffolded_small by at least this margin to count as a real win, not noise. */
    private const FRONTIER_WIN_MARGIN = 0.5;

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
                'lift_result' => self::LIFT_RESULT_INCONCLUSIVE,
                'held_out_sample_count' => 0,
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

        // held_out: only challenges explicitly marked as NOT reused training examples are
        // allowed to prove a lift_result — proxy "passed on training data" never counts.
        $heldOutChallenges = array_values(array_filter($challenges, static fn ($c): bool => is_array($c) && (bool) ($c['held_out'] ?? false)));
        $liftResult = $this->liftResult($heldOutChallenges);

        return [
            'schema_version' => self::SCHEMA,
            'tier_scores' => $tierAvgs,
            'lift_summary' => [
                'lift_from_scaffold' => $liftFromScaffold,
                'frontier_multiplier' => $frontierMultiplier,
            ],
            'failing_dimensions' => $failingDimensions,
            'next_scaffold_improvement' => $worstDim,
            'lift_result' => $liftResult,
            'held_out_sample_count' => count($heldOutChallenges),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $heldOutChallenges
     */
    private function liftResult(array $heldOutChallenges): string
    {
        if (count($heldOutChallenges) < self::MIN_HELD_OUT_SAMPLES) {
            return self::LIFT_RESULT_INCONCLUSIVE;
        }

        $avgs = $this->tierAverages($heldOutChallenges);

        if ($avgs['scaffolded_small'] >= self::ACCEPTABLE_FLOOR) {
            return self::LIFT_RESULT_SCAFFOLD_SUFFICIENT;
        }

        if ($avgs['frontier'] >= $avgs['scaffolded_small'] + self::FRONTIER_WIN_MARGIN) {
            return self::LIFT_RESULT_FRONTIER_WINS;
        }

        return self::LIFT_RESULT_INCONCLUSIVE;
    }

    /**
     * @param  list<array<string,mixed>>  $challenges
     * @return array<string, float>
     */
    private function tierAverages(array $challenges): array
    {
        $n = count($challenges);
        $tierTotals = array_fill_keys(self::TIERS, 0.0);

        foreach ($challenges as $challenge) {
            foreach (self::TIERS as $tier) {
                $data = is_array($challenge[$tier] ?? null) ? $challenge[$tier] : [];
                $dimSum = 0.0;
                foreach (self::DIMENSIONS as $dim) {
                    $dimSum += (float) ($data[$dim] ?? 0);
                }
                $tierTotals[$tier] += $dimSum / count(self::DIMENSIONS);
            }
        }

        $avgs = [];
        foreach (self::TIERS as $tier) {
            $avgs[$tier] = $n > 0 ? round($tierTotals[$tier] / $n, 3) : 0.0;
        }

        return $avgs;
    }
}
