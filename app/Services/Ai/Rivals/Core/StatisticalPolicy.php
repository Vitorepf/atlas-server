<?php

namespace App\Services\Ai\Rivals\Core;

/** Deterministic sample-adequacy gates; report rendering reuses these metrics. */
final class StatisticalPolicy
{
    /**
     * @param  list<RunReceipt>  $receipts
     * @return array{adequate: bool, blockers: list<string>, segments: list<array<string, mixed>>}
     */
    public function evaluate(RunPlan $plan, array $receipts, array $comparisons = []): array
    {
        try {
            $preregistration = Preregistration::load($plan->runId());
        } catch (\Throwable) {
            return [
                'adequate' => false,
                'blockers' => ['preregistration_missing'],
                'segments' => [],
            ];
        }
        if (($plan->data['preregistration_hash'] ?? null) !== $preregistration->hash()) {
            return [
                'adequate' => false,
                'blockers' => ['preregistration_plan_hash_mismatch'],
                'segments' => [],
            ];
        }
        foreach ([
            'suite_id' => $plan->data['suite_id'],
            'case_ids' => $plan->data['case_ids'],
            'arm_ids' => array_column($plan->data['arms'], 'arm_id'),
            'comparisons' => array_values((array) ($plan->data['comparisons'] ?? [])),
            'repetitions' => $plan->data['repetitions'],
        ] as $field => $expected) {
            if (($preregistration->data[$field] ?? null) !== $expected) {
                return [
                    'adequate' => false,
                    'blockers' => ["preregistration_scope_mismatch:{$field}"],
                    'segments' => [],
                ];
            }
        }

        $blockers = [];
        $plannedComparisons = array_values((array) ($preregistration->data['comparisons'] ?? []));
        if ($comparisons === []) {
            $comparisons = $plannedComparisons;
        } elseif (json_encode($comparisons) !== json_encode($plannedComparisons)) {
            $blockers[] = 'comparisons_not_preregistered';
        }
        $targetPower = (float) ($preregistration->data['target_power'] ?? 0.0);
        if ($targetPower < 0.90) {
            $blockers[] = 'preregistered_power_below_90_percent';
        }
        if (($preregistration->data['multiplicity']['method'] ?? null) !== 'holm') {
            $blockers[] = 'multiplicity_policy_not_holm';
        }
        $distinctCases = count(array_unique(array_map(
            fn (RunReceipt $receipt): string => (string) $receipt->data['case_id'],
            $receipts,
        )));
        $minimumCases = (int) $preregistration->data['min_distinct_cases'];
        if ($distinctCases < $minimumCases) {
            $blockers[] = "sample_distinct_cases_below_min:{$distinctCases}<{$minimumCases}";
        }

        $groups = [];
        foreach ($receipts as $receipt) {
            $key = $receipt->data['task_type'].'|'.$receipt->data['arm_id'];
            $groups[$key][] = $receipt;
        }
        $segments = [];
        foreach ($groups as $key => $items) {
            [$taskType, $armId] = explode('|', $key, 2);
            $n = count($items);
            $unitKeys = array_map(
                static fn (RunReceipt $receipt): string => $receipt->data['case_id'].'|'.$receipt->data['repetition'],
                $items,
            );
            if (count($unitKeys) !== count(array_unique($unitKeys))) {
                $blockers[] = 'pseudoreplication_duplicate_unit:'.$taskType.'|'.$armId;
            }
            $expectedAttempts = count($plan->data['case_ids']) * (int) $plan->data['repetitions'];
            if (count(array_unique($unitKeys)) !== $expectedAttempts) {
                $blockers[] = 'itt_denominator_incomplete:'.$taskType.'|'.$armId
                    .'|'.count(array_unique($unitKeys)).'<'.$expectedAttempts;
            }
            $successes = count(array_filter(
                $items,
                fn (RunReceipt $receipt): bool => $receipt->data['status'] === 'success',
            ));
            $interval = self::wilson($successes, $n);
            $environmentFailures = count(array_filter(
                $items,
                fn (RunReceipt $receipt): bool => $receipt->data['failure_class'] === FailureClass::ENVIRONMENT,
            ));
            $environmentRate = $n > 0 ? $environmentFailures / $n : 0.0;
            if ($interval['width'] > (float) $preregistration->data['max_ci_width']) {
                $blockers[] = 'sample_ci_too_wide:'.$taskType.'|'.$armId.'|'
                    .round($interval['width'], 4).'>'.$preregistration->data['max_ci_width'];
            }
            $maxEnvironmentRate = (float) config(
                'atlas_rivals.claim.max_environment_failure_rate',
                0.05,
            );
            if ($environmentRate > $maxEnvironmentRate) {
                $blockers[] = 'environment_failure_rate_exceeded:'.$taskType.'|'.$armId.'|'
                    .round($environmentRate, 4).'>'.$maxEnvironmentRate;
            }
            $segments[] = [
                'task_type' => $taskType,
                'arm_id' => $armId,
                'n' => $n,
                'successes' => $successes,
                'success_rate' => $successes / $n, // $n = count($items) >= 1 by grouping
                'wilson_95' => $interval,
                'environment_failure_rate' => $environmentRate,
            ];
        }

        $adjustedComparisons = self::holm($comparisons, (float) $preregistration->data['alpha']);
        foreach ($adjustedComparisons as $comparison) {
            if (($comparison['raw_p_value'] ?? 1.0) < (float) $preregistration->data['alpha']
                && ($comparison['significant'] ?? false) !== true) {
                $blockers[] = 'multiplicity_not_significant_after_holm:'.$comparison['id'];
            }
        }

        $uniqueBlockers = array_values(array_unique($blockers));

        return [
            'adequate' => $blockers === [],
            'blockers' => $uniqueBlockers,
            'segments' => $segments,
            'preregistration' => [
                'target_power' => $targetPower,
                'analysis_population' => $preregistration->data['analysis_population'],
            ],
            'multiplicity' => $preregistration->data['multiplicity'],
            'comparisons' => $adjustedComparisons,
            // byte-stable hashes so a claim replay reproduces the same policy and result
            'policy_hash' => self::policyHash($preregistration->data),
            'result_hash' => self::resultHash([
                'segments' => $segments,
                'comparisons' => $adjustedComparisons,
                'blockers' => $uniqueBlockers,
            ]),
        ];
    }

    /** @param list<array{id?: string, p_value: float|int}> $comparisons */
    public static function holm(array $comparisons, float $alpha = 0.05): array
    {
        $ordered = [];
        foreach ($comparisons as $index => $comparison) {
            $pValue = (float) ($comparison['p_value'] ?? -1.0);
            if ($pValue < 0.0 || $pValue > 1.0) {
                $pValue = 1.0;
            }
            $ordered[] = [
                'index' => $index,
                'id' => (string) ($comparison['id'] ?? "comparison_{$index}"),
                'raw_p_value' => $pValue,
            ];
        }
        usort($ordered, static fn (array $a, array $b): int => $a['raw_p_value'] <=> $b['raw_p_value']);
        $previous = 0.0;
        foreach ($ordered as $rank => &$comparison) {
            $adjusted = min(1.0, max($previous, $comparison['raw_p_value'] * (count($ordered) - $rank)));
            $comparison['adjusted_p_value'] = round($adjusted, 6);
            $comparison['significant'] = $adjusted <= $alpha;
            $previous = $adjusted;
        }
        unset($comparison);
        usort($ordered, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static function (array $comparison): array {
            unset($comparison['index']);

            return $comparison;
        }, $ordered);
    }

    /** @return array{low: float, high: float, width: float} */
    public static function wilson(int $successes, int $n, float $z = 1.959963984540054): array
    {
        if ($n <= 0) {
            return ['low' => 0.0, 'high' => 1.0, 'width' => 1.0];
        }
        $p = $successes / $n;
        $z2 = $z ** 2;
        $denominator = 1 + ($z2 / $n);
        $center = ($p + ($z2 / (2 * $n))) / $denominator;
        $margin = ($z / $denominator) * sqrt(
            (($p * (1 - $p)) / $n) + ($z2 / (4 * ($n ** 2)))
        );
        $low = max(0.0, $center - $margin);
        $high = min(1.0, $center + $margin);

        return [
            'low' => round($low, 6),
            'high' => round($high, 6),
            'width' => round($high - $low, 6),
        ];
    }

    /**
     * Intent-to-treat success estimate with a hierarchical bootstrap: resample cases
     * (the statistical unit) with replacement, then resample the repetitions nested in
     * each drawn case. This propagates between-case variance that a flat bootstrap hides.
     * Deterministic given the seed (local LCG, no global RNG) so a run replays identically.
     *
     * @param  array<string, list<bool>>  $casesUnits  case_id => repetition successes
     * @return array{estimate: float, ci_low: float, ci_high: float, iterations: int}
     */
    public static function hierarchicalBootstrap(array $casesUnits, int $iterations = 2000, int $seed = 1): array
    {
        $caseIds = array_keys($casesUnits);
        $nCases = count($caseIds);
        if ($nCases === 0 || $iterations < 1) {
            return ['estimate' => 0.0, 'ci_low' => 0.0, 'ci_high' => 1.0, 'iterations' => 0];
        }

        $observedSuccess = 0;
        $observedTotal = 0;
        foreach ($casesUnits as $reps) {
            foreach ($reps as $r) {
                $observedSuccess += $r ? 1 : 0;
                $observedTotal++;
            }
        }
        $estimate = $observedTotal > 0 ? $observedSuccess / $observedTotal : 0.0;

        $state = ($seed & 0x7FFFFFFF) ?: 1;
        // Scale by the high bits: an LCG's low-order bits have a very short period, so
        // `state % bound` degenerates. Float-scaling the full 31-bit range is uniform.
        $next = static function (int $bound) use (&$state): int {
            $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;

            return $bound > 0 ? (int) (($state / 2147483648.0) * $bound) : 0;
        };

        $rates = [];
        for ($i = 0; $i < $iterations; $i++) {
            $sum = 0;
            $count = 0;
            for ($c = 0; $c < $nCases; $c++) {
                $reps = $casesUnits[$caseIds[$next($nCases)]];
                $m = count($reps);
                if ($m === 0) {
                    continue;
                }
                for ($k = 0; $k < $m; $k++) {
                    $sum += $reps[$next($m)] ? 1 : 0;
                    $count++;
                }
            }
            $rates[] = $count > 0 ? $sum / $count : 0.0;
        }
        sort($rates);

        return [
            'estimate' => round($estimate, 6),
            'ci_low' => round(self::percentile($rates, 2.5), 6),
            'ci_high' => round(self::percentile($rates, 97.5), 6),
            'iterations' => $iterations,
        ];
    }

    /**
     * Newcombe hybrid-score CI for the difference of two proportions — correct at the
     * boundaries where a Wald interval overshoots [-1, 1].
     *
     * @return array{diff: float, ci_low: float, ci_high: float}
     */
    public static function newcombeDiff(int $sA, int $nA, int $sB, int $nB): array
    {
        $pA = $nA > 0 ? $sA / $nA : 0.0;
        $pB = $nB > 0 ? $sB / $nB : 0.0;
        $wA = self::wilson($sA, $nA);
        $wB = self::wilson($sB, $nB);
        $diff = $pA - $pB;
        $lower = $diff - sqrt(($pA - $wA['low']) ** 2 + ($wB['high'] - $pB) ** 2);
        $upper = $diff + sqrt(($wA['high'] - $pA) ** 2 + ($pB - $wB['low']) ** 2);

        return [
            'diff' => round($diff, 6),
            'ci_low' => round(max(-1.0, $lower), 6),
            'ci_high' => round(min(1.0, $upper), 6),
        ];
    }

    /**
     * Sensitivity of the success rate to missing attempts. ITT (missing = failure) is
     * the conservative claim basis; best_case (missing = success) is reported for
     * transparency and must never become the claim rate.
     *
     * @return array{observed_only: float, itt: float, best_case: float}
     */
    public static function sensitivity(int $successes, int $observedN, int $missing): array
    {
        $total = $observedN + $missing;

        return [
            'observed_only' => $observedN > 0 ? round($successes / $observedN, 6) : 0.0,
            'itt' => $total > 0 ? round($successes / $total, 6) : 0.0,
            'best_case' => $total > 0 ? round(($successes + $missing) / $total, 6) : 0.0,
        ];
    }

    /**
     * Approximate power of a two-proportion two-sided z-test at the given per-arm n.
     * Used to reject an underpowered run before it runs (box: power >= 90%).
     */
    public static function computedPower(float $p1, float $p2, int $nPerArm, float $alpha = 0.05): float
    {
        $effect = abs($p1 - $p2);
        if ($nPerArm <= 0 || $effect <= 0.0) {
            return 0.0;
        }
        $se = sqrt(($p1 * (1 - $p1) / $nPerArm) + ($p2 * (1 - $p2) / $nPerArm));
        if ($se <= 0.0) {
            return 1.0;
        }
        $zAlpha = self::normalInv(1 - $alpha / 2);
        $ratio = $effect / $se;
        $power = self::normalCdf($ratio - $zAlpha) + self::normalCdf(-$ratio - $zAlpha);

        return round(min(1.0, max(0.0, $power)), 6);
    }

    /**
     * Poisson rate CI for event counts (e.g. escaped defects) over an exposure window.
     *
     * @return array{rate: float, ci_low: float, ci_high: float}
     */
    public static function poissonRateCi(int $events, float $exposure): array
    {
        $exposure = max($exposure, 1e-9);
        $rate = $events / $exposure;
        // ponytail: Wald interval on sqrt(k); upgrade to Garwood exact if counts get tiny.
        $margin = 1.959963984540054 * sqrt(max($events, 0)) / $exposure;

        return [
            'rate' => round($rate, 6),
            'ci_low' => round(max(0.0, $rate - $margin), 6),
            'ci_high' => round($rate + $margin, 6),
        ];
    }

    /**
     * Restricted mean survival/response time up to a horizon: the mean of each observed
     * time censored at `horizon`. A time-to-event ITT summary that a raw mean (which
     * drops or over-weights censored runs) cannot give.
     *
     * @param  list<int|float>  $times
     */
    public static function restrictedMeanTime(array $times, float $horizon): float
    {
        if ($times === [] || $horizon <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($times as $t) {
            $sum += min((float) $t, $horizon);
        }

        return round($sum / count($times), 6);
    }

    /** @param array<string,mixed> $preregistration */
    public static function policyHash(array $preregistration): string
    {
        return self::stableHash($preregistration);
    }

    /** @param array<string,mixed> $analysis */
    public static function resultHash(array $analysis): string
    {
        return self::stableHash($analysis);
    }

    /** @param list<float> $sorted */
    private static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int) round(($p / 100) * ($n - 1));

        return $sorted[max(0, min($n - 1, $idx))];
    }

    private static function stableHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }

    private static function normalCdf(float $x): float
    {
        return 0.5 * (1 + self::erf($x / M_SQRT2));
    }

    /** Abramowitz-Stegun 7.1.26 approximation of the error function. */
    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /** Acklam's rational approximation of the inverse standard normal CDF. */
    private static function normalInv(float $p): float
    {
        if ($p <= 0.0) {
            return -INF;
        }
        if ($p >= 1.0) {
            return INF;
        }
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $plow = 0.02425;
        $phigh = 1 - $plow;
        if ($p < $plow) {
            $q = sqrt(-2 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        if ($p > $phigh) {
            $q = sqrt(-2 * log(1 - $p));

            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}
