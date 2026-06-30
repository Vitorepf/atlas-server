<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compares predicted leverage against realized muscle outcomes so future task ranking
 * learns from systematic over- or under-estimation.
 *
 * Rewards:  downstream_unlocks + durable green_evidence > raw commit_count.
 * Penalizes: give_back churn + delivered tasks with capability_delta=none.
 *
 * INPUT:
 *   $forecasts: list<{ task_family:string, predicted_leverage:string(high|medium|low),
 *                       predicted_outcome?:string }>
 *   $outcomes:  list<{ task_family:string, actual_outcome:string(delivered|give_back|proxy),
 *                       capability_delta?:string(high|medium|low|none),
 *                       downstream_unlocks?:int, green_evidence?:bool,
 *                       give_back_churn?:int, commit_count?:int }>
 *
 * OUTPUT per task family (calibrations list):
 *   { task_family, forecast_error:float[0..1], confidence_adjustment:float[-1..1],
 *     repeated_overclaim_flags:list<string>, next_ranking_hint:string(up|down|hold) }
 *
 * Top-level: schema, calibrations, repeated_overclaim_flags (cross-family summary)
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainImpactForecastCalibrator
{
    public const SCHEMA = 'atlas.external_brain.impact_forecast_calibrator.v1';

    public const OUTCOME_DELIVERED = 'delivered';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_PROXY = 'proxy';

    public const LEVERAGE_HIGH   = 'high';

    public const LEVERAGE_MEDIUM = 'medium';

    public const LEVERAGE_LOW    = 'low';

    /** Minimum gap (predicted - actual) that counts as an overclaim per observation. */
    private const OVERCLAIM_THRESHOLD = 0.25;

    /** Minimum observations in a family to declare repeated_overclaim. */
    private const OVERCLAIM_MIN_OBSERVATIONS = 2;

    /** Minimum gap between actual and predicted for next_ranking_hint to move up/down. */
    private const RANKING_GAP = 0.20;

    /**
     * @param  list<array<string,mixed>>  $forecasts
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    public function calibrate(array $forecasts, array $outcomes): array
    {
        $forecastsByFamily = $this->groupBy('task_family', $forecasts);
        $outcomesByFamily  = $this->groupBy('task_family', $outcomes);

        $allFamilies = array_unique(array_merge(array_keys($forecastsByFamily), array_keys($outcomesByFamily)));
        sort($allFamilies, SORT_STRING);

        $calibrations           = [];
        $repeatedOverclaimFlags = [];

        foreach ($allFamilies as $family) {
            $familyForecasts = $forecastsByFamily[$family] ?? [];
            $familyOutcomes  = $outcomesByFamily[$family] ?? [];

            $predictedScore = $this->averagePredictedScore($familyForecasts);
            $actualScore    = $this->averageActualScore($familyOutcomes);

            $forecastError       = round(abs($predictedScore - $actualScore), 4);
            $confidenceAdj       = round(max(-1.0, min(1.0, $actualScore - $predictedScore)), 4);
            $nextRankingHint     = $this->rankingHint($actualScore, $predictedScore);
            $familyOverclaimFlags = $this->familyOverclaimFlags($family, $familyForecasts, $familyOutcomes);

            if ($familyOverclaimFlags !== []) {
                $repeatedOverclaimFlags = array_merge($repeatedOverclaimFlags, $familyOverclaimFlags);
            }

            [$overclaim, $underclaim, $multiplier] = $this->perFamilyRates(
                $familyForecasts,
                $familyOutcomes,
            );

            $nextBatchConstraints = $this->nextBatchConstraints($overclaim, $multiplier, $nextRankingHint, $familyOverclaimFlags);

            $calibrations[] = [
                'task_family'              => $family,
                'forecast_error'           => $forecastError,
                'confidence_adjustment'    => $confidenceAdj,
                'calibration_bias'         => round(-$confidenceAdj, 4),
                'overclaim_rate'           => $overclaim,
                'underclaim_rate'          => $underclaim,
                'next_forecast_multiplier' => $multiplier,
                'repeated_overclaim_flags' => $familyOverclaimFlags,
                'next_ranking_hint'        => $nextRankingHint,
                'next_batch_constraints'   => $nextBatchConstraints,
            ];
        }

        sort($repeatedOverclaimFlags, SORT_STRING);
        $repeatedOverclaimFlags = array_values(array_unique($repeatedOverclaimFlags));

        return [
            'schema'                  => self::SCHEMA,
            'calibrations'            => $calibrations,
            'repeated_overclaim_flags' => $repeatedOverclaimFlags,
        ];
    }

    /**
     * Predicted leverage score: high=0.9, medium=0.5, low=0.1, missing=0.5.
     *
     * @param  list<array<string,mixed>>  $forecasts
     */
    private function averagePredictedScore(array $forecasts): float
    {
        if ($forecasts === []) {
            return 0.5;
        }

        $total = 0.0;
        foreach ($forecasts as $f) {
            $total += $this->leverageScore((string) ($f['predicted_leverage'] ?? ''));
        }

        return round($total / count($forecasts), 4);
    }

    /** @param  list<array<string,mixed>>  $outcomes */
    private function averageActualScore(array $outcomes): float
    {
        if ($outcomes === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($outcomes as $o) {
            $total += $this->actualLeverageScore($o);
        }

        return round($total / count($outcomes), 4);
    }

    /** @param  array<string,mixed>  $outcome */
    private function actualLeverageScore(array $outcome): float
    {
        $kind  = (string) ($outcome['actual_outcome'] ?? self::OUTCOME_GIVE_BACK);
        $delta = strtolower((string) ($outcome['capability_delta'] ?? ''));

        $base = match ($kind) {
            self::OUTCOME_GIVE_BACK => 0.0,
            self::OUTCOME_PROXY     => 0.1,
            self::OUTCOME_DELIVERED => match ($delta) {
                'high'   => 0.8,
                'medium' => 0.5,
                'low'    => 0.3,
                'none'   => 0.1,  // delivered but zero capability gain
                default  => 0.4,
            },
            default => 0.0,
        };

        // Downstream unlocks: more valuable than bare commit count.
        $unlocks = max(0, (int) ($outcome['downstream_unlocks'] ?? 0));
        $unlockBonus = min(0.2, $unlocks * 0.05);

        // Green evidence bonus — suppressed for proxy outcomes and no-delta deliveries (AC2):
        // green tests on a proxy/no-delta commit do not indicate real capability gain.
        $suppressGreen = ($kind === self::OUTCOME_PROXY) || ($kind === self::OUTCOME_DELIVERED && $delta === 'none');
        $greenBonus = (! $suppressGreen && (bool) ($outcome['green_evidence'] ?? false)) ? 0.05 : 0.0;

        // Give-back churn penalty (applies even to "delivered" tasks with high churn).
        $churn   = max(0, (int) ($outcome['give_back_churn'] ?? 0));
        $penalty = min(0.45, $churn * 0.15);

        return max(0.0, min(1.0, $base + $unlockBonus + $greenBonus - $penalty));
    }

    private function leverageScore(string $level): float
    {
        return match (strtolower($level)) {
            self::LEVERAGE_HIGH   => 0.9,
            self::LEVERAGE_LOW    => 0.1,
            default               => 0.5,
        };
    }

    private function rankingHint(float $actual, float $predicted): string
    {
        $diff = $actual - $predicted;

        return match (true) {
            $diff >= self::RANKING_GAP  => 'up',
            $diff <= -self::RANKING_GAP => 'down',
            default                     => 'hold',
        };
    }

    /**
     * Returns overclaim reason strings for this family if repeated overclaim is detected.
     *
     * @param  list<array<string,mixed>>  $familyForecasts
     * @param  list<array<string,mixed>>  $familyOutcomes
     * @return list<string>
     */
    private function familyOverclaimFlags(string $family, array $familyForecasts, array $familyOutcomes): array
    {
        $flags = [];

        // Need enough observations for "repeated".
        $observations = max(count($familyForecasts), count($familyOutcomes));
        if ($observations < self::OVERCLAIM_MIN_OBSERVATIONS) {
            return [];
        }

        $predictedScore = $this->averagePredictedScore($familyForecasts);
        $actualScore    = $this->averageActualScore($familyOutcomes);
        $gap            = $predictedScore - $actualScore;

        if ($gap >= self::OVERCLAIM_THRESHOLD) {
            $flags[] = sprintf(
                'repeated_overclaim:%s:predicted=%.2f:actual=%.2f',
                $family,
                $predictedScore,
                $actualScore,
            );
        }

        return $flags;
    }

    /**
     * Returns [overclaim_rate, underclaim_rate, next_forecast_multiplier] for a family.
     * Pairs forecasts to outcomes by index (up to min of both counts).
     *
     * next_forecast_multiplier = clamp(1.0 − calibration_bias, 0.5, 1.5)
     * where calibration_bias = avg(predicted_score − actual_score) per pair.
     *
     * @param  list<array<string,mixed>>  $forecasts
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{float, float, float}
     */
    private function perFamilyRates(array $forecasts, array $outcomes): array
    {
        $pairs = min(count($forecasts), count($outcomes));

        if ($pairs === 0) {
            $bias       = $this->averagePredictedScore($forecasts) - $this->averageActualScore($outcomes);
            $multiplier = round(max(0.5, min(1.5, 1.0 - $bias)), 4);
            return [0.0, 0.0, $multiplier];
        }

        $overclaims  = 0;
        $underclaims = 0;
        $totalBias   = 0.0;

        for ($i = 0; $i < $pairs; $i++) {
            $predicted = $this->leverageScore((string) ($forecasts[$i]['predicted_leverage'] ?? ''));
            $actual    = $this->actualLeverageScore($outcomes[$i]);
            $diff      = $predicted - $actual;
            $totalBias += $diff;

            if ($diff >= self::OVERCLAIM_THRESHOLD) {
                $overclaims++;
            } elseif ($diff <= -self::OVERCLAIM_THRESHOLD) {
                $underclaims++;
            }
        }

        $overclaim_rate  = round($overclaims / $pairs, 4);
        $underclaim_rate = round($underclaims / $pairs, 4);
        $bias            = round($totalBias / $pairs, 4);
        $multiplier      = round(max(0.5, min(1.5, 1.0 - $bias)), 4);

        return [$overclaim_rate, $underclaim_rate, $multiplier];
    }

    /**
     * Translates calibration signal into concrete constraints for the NEXT batch of this family —
     * the point where forecast accuracy actually changes future ranking, not just describes the past.
     *
     * @param  list<string>  $overclaimFlags
     * @return list<string>
     */
    private function nextBatchConstraints(float $overclaimRate, float $multiplier, string $rankingHint, array $overclaimFlags): array
    {
        $constraints = [];

        if ($overclaimFlags !== [] || $overclaimRate >= 0.5) {
            $constraints[] = 'require_stronger_evidence_before_high_leverage_claim';
        }
        if ($rankingHint === 'down' || $multiplier <= 0.75) {
            $constraints[] = 'cap_batch_size:1';
        }
        if ($rankingHint === 'up' && $multiplier >= 1.0) {
            $constraints[] = 'eligible_for_increased_batch_size';
        }
        if ($constraints === []) {
            $constraints[] = 'no_constraint';
        }

        return $constraints;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupBy(string $key, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $val = (string) ($row[$key] ?? '');
            if ($val !== '') {
                $result[$val][] = $row;
            }
        }

        return $result;
    }
}
