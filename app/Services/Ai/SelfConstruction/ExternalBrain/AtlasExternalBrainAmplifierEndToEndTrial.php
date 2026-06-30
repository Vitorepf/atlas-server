<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure end-to-end trial. Replays a candidate task through three tiers using
 * supplied benchmark facts and recommends which tier to use.
 *
 * Input facts:
 *   benchmark_dimensions     — map of dimension_name → {small_model_score,
 *                              scaffolded_score, frontier_score}.
 *   quality_floor            — minimum dimension score for tier acceptance (default 0.75).
 *   min_lift_for_promotion   — minimum scaffold_lift required for promote_scaffold (default 0.10).
 *
 * AC2 — run() returns:
 *   tier_scores      — {small_model, scaffolded_small_model, frontier_model} (average over dims).
 *   scaffold_lift    — tier_scores.scaffolded - tier_scores.small.
 *   frontier_gain    — tier_scores.frontier - tier_scores.scaffolded.
 *   recommendation   — see AC3.
 *
 * AC3 — recommendation priority (first match wins):
 *   promote_scaffold        — scaffolded min-dim-score >= quality_floor AND scaffold_lift >= min_lift.
 *   use_frontier            — frontier min-dim-score >= quality_floor AND scaffolded min-dim < floor.
 *   use_scaffolded_small_model — scaffolded meets floor but lift is insufficient (< min_lift).
 *   use_small_model         — nothing meets quality_floor, or all tiers perform similarly.
 *
 * AC4: provider-free, deterministic, only supplied benchmark facts.
 *
 * Extended input (all optional):
 *   quality_metrics — {
 *     baseline:   {valid_seed_rate, accepted_by_gate_rate, later_green_rate, give_back_rate, proxy_rate, average_cost},
 *     scaffolded: {same keys},
 *     sample_count: int,
 *   }
 *
 * Extended output (additive):
 *   baseline_vs_scaffolded — {verdict, trial_passes, lift, cost_delta, proxy_rate_delta, give_back_delta}
 *     verdict: lift | no_lift | cheaper_but_worse | low_sample | no_data
 *     trial_passes: false when cheaper_but_worse or low_sample
 *
 * Pure, no providers, no I/O.
 */
final class AtlasExternalBrainAmplifierEndToEndTrial
{
    public const SCHEMA = 'atlas.external_brain.amplifier_end_to_end_trial.v1';

    private const DEFAULT_QUALITY_FLOOR = 0.75;
    private const DEFAULT_MIN_LIFT      = 0.10;

    private const MIN_SAMPLE_COUNT          = 10;
    private const MIN_QUALITY_LIFT          = 0.05;
    private const PROXY_WORSE_THRESHOLD     = 0.05;
    private const GIVE_BACK_WORSE_THRESHOLD = 0.05;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function run(array $facts): array
    {
        $rawDims  = is_array($facts['benchmark_dimensions'] ?? null) ? $facts['benchmark_dimensions'] : [];
        $floor    = (float) ($facts['quality_floor']          ?? self::DEFAULT_QUALITY_FLOOR);
        $minLift  = (float) ($facts['min_lift_for_promotion'] ?? self::DEFAULT_MIN_LIFT);

        // Extract per-dimension scores.
        $smallScores   = [];
        $scaffScores   = [];
        $frontScores   = [];
        $breakdown     = [];

        foreach ($rawDims as $dim => $scores) {
            if (! is_array($scores)) {
                continue;
            }
            $small = max(0.0, min(1.0, (float) ($scores['small_model_score'] ?? 0.0)));
            $scaff = max(0.0, min(1.0, (float) ($scores['scaffolded_score']  ?? 0.0)));
            $front = max(0.0, min(1.0, (float) ($scores['frontier_score']    ?? 0.0)));

            $smallScores[]   = $small;
            $scaffScores[]   = $scaff;
            $frontScores[]   = $front;

            $breakdown[(string) $dim] = [
                'small_model'           => $small,
                'scaffolded_small_model' => $scaff,
                'frontier_model'        => $front,
            ];
        }

        $smallAvg = $this->avg($smallScores);
        $scaffAvg = $this->avg($scaffScores);
        $frontAvg = $this->avg($frontScores);

        $scaffoldLift = round($scaffAvg - $smallAvg, 6);
        $frontierGain = round($frontAvg - $scaffAvg, 6);

        // Min-dimension scores for floor check.
        $scaffMin = empty($scaffScores) ? 0.0 : min($scaffScores);
        $frontMin = empty($frontScores) ? 0.0 : min($frontScores);

        $scaffMeetsFloor  = $scaffMin >= $floor;
        $frontMeetsFloor  = $frontMin >= $floor;
        $liftSufficient   = $scaffoldLift >= $minLift;

        $recommendation = $this->recommend($scaffMeetsFloor, $frontMeetsFloor, $liftSufficient);

        $qm = is_array($facts['quality_metrics'] ?? null) ? $facts['quality_metrics'] : [];

        return [
            'schema_version'     => self::SCHEMA,
            'tier_scores'        => [
                'small_model'            => round($smallAvg, 4),
                'scaffolded_small_model' => round($scaffAvg, 4),
                'frontier_model'         => round($frontAvg, 4),
            ],
            'scaffold_lift'      => round($scaffoldLift, 4),
            'frontier_gain'      => round($frontierGain, 4),
            'recommendation'     => $recommendation,
            'dimension_breakdown' => $breakdown,
            'quality_assessment' => [
                'scaffold_meets_floor' => $scaffMeetsFloor,
                'frontier_meets_floor' => $frontMeetsFloor,
                'lift_sufficient'      => $liftSufficient,
            ],
            'baseline_vs_scaffolded' => $this->computeQualityLift(
                is_array($qm['baseline']   ?? null) ? $qm['baseline']   : [],
                is_array($qm['scaffolded'] ?? null) ? $qm['scaffolded'] : [],
                (int) ($qm['sample_count'] ?? 0),
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function computeQualityLift(array $baseline, array $scaffolded, int $sampleCount): array
    {
        if ($baseline === [] || $scaffolded === []) {
            return ['verdict' => 'no_data', 'trial_passes' => false, 'lift' => 0.0];
        }

        if ($sampleCount > 0 && $sampleCount < self::MIN_SAMPLE_COUNT) {
            return ['verdict' => 'low_sample', 'trial_passes' => false, 'lift' => 0.0, 'sample_count' => $sampleCount];
        }

        // Higher-is-better dims.
        $positiveLifts = [];
        foreach (['valid_seed_rate', 'accepted_by_gate_rate', 'later_green_rate'] as $dim) {
            if (isset($baseline[$dim], $scaffolded[$dim])) {
                $positiveLifts[] = (float) $scaffolded[$dim] - (float) $baseline[$dim];
            }
        }

        // Lower-is-better dims (delta > 0 means worsening).
        $proxyDelta    = isset($baseline['proxy_rate'],    $scaffolded['proxy_rate'])
            ? (float) $scaffolded['proxy_rate']    - (float) $baseline['proxy_rate']    : null;
        $giveBackDelta = isset($baseline['give_back_rate'], $scaffolded['give_back_rate'])
            ? (float) $scaffolded['give_back_rate'] - (float) $baseline['give_back_rate'] : null;
        $costDelta     = isset($baseline['average_cost'],  $scaffolded['average_cost'])
            ? (float) $scaffolded['average_cost']  - (float) $baseline['average_cost']  : null;

        // AC2: cheaper-but-worse guard.
        $costImproved     = $costDelta !== null && $costDelta < 0;
        $proxyWorsened    = $proxyDelta !== null    && $proxyDelta    > self::PROXY_WORSE_THRESHOLD;
        $giveBackWorsened = $giveBackDelta !== null && $giveBackDelta > self::GIVE_BACK_WORSE_THRESHOLD;

        // Composite lift: positive dims + improvement on negative dims.
        $negativeLifts = [];
        if ($proxyDelta !== null)    { $negativeLifts[] = -$proxyDelta; }
        if ($giveBackDelta !== null) { $negativeLifts[] = -$giveBackDelta; }
        $allLifts       = array_merge($positiveLifts, $negativeLifts);
        $compositeScore = $allLifts !== [] ? array_sum($allLifts) / count($allLifts) : 0.0;

        $result = [
            'lift'             => round($compositeScore, 4),
            'cost_delta'       => $costDelta     !== null ? round($costDelta, 4)     : null,
            'proxy_rate_delta' => $proxyDelta    !== null ? round($proxyDelta, 4)    : null,
            'give_back_delta'  => $giveBackDelta !== null ? round($giveBackDelta, 4) : null,
        ];

        if ($costImproved && ($proxyWorsened || $giveBackWorsened)) {
            return array_merge($result, ['verdict' => 'cheaper_but_worse', 'trial_passes' => false]);
        }

        $verdict = $compositeScore >= self::MIN_QUALITY_LIFT ? 'lift' : 'no_lift';

        return array_merge($result, ['verdict' => $verdict, 'trial_passes' => $verdict === 'lift']);
    }

    private function avg(array $scores): float
    {
        if (empty($scores)) {
            return 0.0;
        }

        return array_sum($scores) / count($scores);
    }

    private function recommend(bool $scaffMeets, bool $frontMeets, bool $liftOk): string
    {
        // AC3: promote_scaffold → scaffolded meets floor AND lift sufficient.
        if ($scaffMeets && $liftOk) {
            return 'promote_scaffold';
        }

        // use_frontier → frontier meets floor but scaffold doesn't.
        if ($frontMeets && ! $scaffMeets) {
            return 'use_frontier';
        }

        // scaffold meets floor but lift too small → still better than small.
        if ($scaffMeets) {
            return 'use_scaffolded_small_model';
        }

        // Nothing meets floor, or no meaningful lift anywhere.
        return 'use_small_model';
    }
}
