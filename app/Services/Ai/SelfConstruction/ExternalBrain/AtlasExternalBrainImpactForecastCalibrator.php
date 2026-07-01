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
 * Top-level: schema, calibrations, repeated_overclaim_flags (cross-family summary),
 * source_calibrations, repeated_overclaim_sources (the same computation grouped by an optional
 * `source` field on each forecast/outcome — e.g. which forecaster/model produced the prediction —
 * so repeated overclaim can be traced to a SOURCE, not only a task family). Forecasts/outcomes
 * without a `source` field are simply excluded from source_calibrations; every existing caller that
 * never supplies `source` gets an empty source_calibrations/repeated_overclaim_sources, so this is
 * fully additive.
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

    /** Families with fewer observations than this cannot support a confident up/down hint. */
    private const SAMPLE_SIZE_FLOOR = 3;

    public const ADJUSTMENT_STATUS_SUFFICIENT   = 'sufficient_evidence';
    public const ADJUSTMENT_STATUS_INSUFFICIENT = 'insufficient_evidence';

    /**
     * @param  list<array<string,mixed>>  $forecasts
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    public function calibrate(array $forecasts, array $outcomes): array
    {
        [$calibrations, $repeatedOverclaimFlags] = $this->buildCalibrations('task_family', $forecasts, $outcomes);
        [$sourceCalibrations, $repeatedOverclaimSources] = $this->buildCalibrations('source', $forecasts, $outcomes);

        return [
            'schema'                     => self::SCHEMA,
            'calibrations'               => $calibrations,
            'repeated_overclaim_flags'   => $repeatedOverclaimFlags,
            'source_calibrations'        => $sourceCalibrations,
            'repeated_overclaim_sources' => $repeatedOverclaimSources,
        ];
    }

    /**
     * Builds one calibration record per distinct value of $groupKey (e.g. 'task_family' or
     * 'source') present on the forecasts/outcomes. Identical computation regardless of which
     * field is grouped on — only the grouping key and the entry's identifying field name change.
     *
     * @param  list<array<string,mixed>>  $forecasts
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{0:list<array<string,mixed>>, 1:list<string>}
     */
    private function buildCalibrations(string $groupKey, array $forecasts, array $outcomes): array
    {
        $forecastsByGroup = $this->groupBy($groupKey, $forecasts);
        $outcomesByGroup  = $this->groupBy($groupKey, $outcomes);

        $allGroups = array_unique(array_merge(array_keys($forecastsByGroup), array_keys($outcomesByGroup)));
        sort($allGroups, SORT_STRING);

        $calibrations           = [];
        $repeatedOverclaimFlags = [];

        foreach ($allGroups as $group) {
            $groupForecasts = $forecastsByGroup[$group] ?? [];
            $groupOutcomes  = $outcomesByGroup[$group] ?? [];

            $predictedScore = $this->averagePredictedScore($groupForecasts);
            $actualScore    = $this->averageActualScore($groupOutcomes);

            $sampleSize          = max(count($groupForecasts), count($groupOutcomes));
            $hasEnoughEvidence   = $sampleSize >= self::SAMPLE_SIZE_FLOOR;
            $adjustmentStatus    = $hasEnoughEvidence ? self::ADJUSTMENT_STATUS_SUFFICIENT : self::ADJUSTMENT_STATUS_INSUFFICIENT;
            // Confidence band shrinks as evidence accumulates (never reaches 0, never exceeds 1).
            $confidenceBand      = round(min(1.0, 1.0 / sqrt(max(1, $sampleSize))), 4);

            $forecastError       = round(abs($predictedScore - $actualScore), 4);
            $confidenceAdj       = round(max(-1.0, min(1.0, $actualScore - $predictedScore)), 4);
            // AC2: below the sample-size floor, a single lucky/unlucky outcome must never
            // produce an aggressive up/down ranking hint — hold until more evidence arrives.
            $nextRankingHint     = $hasEnoughEvidence ? $this->rankingHint($actualScore, $predictedScore) : 'hold';
            $groupOverclaimFlags = $this->familyOverclaimFlags($group, $groupForecasts, $groupOutcomes);

            if ($groupOverclaimFlags !== []) {
                $repeatedOverclaimFlags = array_merge($repeatedOverclaimFlags, $groupOverclaimFlags);
            }

            [$overclaim, $underclaim, $multiplier] = $this->perFamilyRates(
                $groupForecasts,
                $groupOutcomes,
            );

            $nextBatchConstraints = $this->nextBatchConstraints($overclaim, $multiplier, $nextRankingHint, $groupOverclaimFlags);

            $calibrations[] = [
                $groupKey                  => $group,
                'sample_size'              => $sampleSize,
                'confidence_band'          => $confidenceBand,
                'adjustment_status'        => $adjustmentStatus,
                'forecast_error'           => $forecastError,
                'confidence_adjustment'    => $confidenceAdj,
                'calibration_bias'         => round(-$confidenceAdj, 4),
                'overclaim_rate'           => $overclaim,
                'underclaim_rate'          => $underclaim,
                'next_forecast_multiplier' => $multiplier,
                'repeated_overclaim_flags' => $groupOverclaimFlags,
                'next_ranking_hint'        => $nextRankingHint,
                'next_batch_constraints'   => $nextBatchConstraints,
            ];
        }

        sort($repeatedOverclaimFlags, SORT_STRING);
        $repeatedOverclaimFlags = array_values(array_unique($repeatedOverclaimFlags));

        return [$calibrations, $repeatedOverclaimFlags];
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
