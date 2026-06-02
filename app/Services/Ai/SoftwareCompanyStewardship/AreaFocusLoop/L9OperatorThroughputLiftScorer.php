<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L9-Q1 sovereign engineering: measures operator-validated engineering
 * throughput lift, override-rate movement and the count of delegated
 * decisions that exceeded their proven bounds.
 *
 * The L9-Q1 criterion is "measured operator leverage, not autonomy theatre":
 * the operator must validate MORE engineering work per unit of their own
 * effort (positive throughput lift) without the system drifting outside the
 * proven invariant boundary (zero out-of-bound decisions) and with enough
 * observed decisions to trust the measurement (sufficient sample size).
 *
 * Each window (baseline and current) carries:
 *   - validated_decisions: operator-validated engineering decisions in the window
 *   - period_units:        the operator effort units the window spans (the
 *                          denominator for the throughput rate)
 *   - decision_count:      total delegated decisions (denominator for override rate)
 *   - override_count:      operator overrides observed in the window
 *   - out_of_bound_count:  decisions that exceeded the proven Q2 boundary
 *
 * throughput_lift is the RELATIVE lift of the operator-validated throughput
 * rate (validated_decisions / period_units) of current over baseline, so a
 * faster operator (more validated decisions per unit of their effort) earns a
 * positive lift and a slower one earns a negative lift. override_rate_delta is
 * the change in override rate (override_count / decision_count); a falling
 * override rate (the operator agreeing more) is a negative delta.
 *
 * Pure: every returned field is COMPUTED from the method inputs through real
 * arithmetic. No I/O, DB, Eloquent, facade, provider call, clock, randomness
 * or external state. Identical inputs always yield identical output.
 */
final class L9OperatorThroughputLiftScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.operator_throughput_lift.v1';

    /**
     * Minimum number of operator-validated decisions in the current window
     * before the measurement is trusted at all; below this the score returns
     * the insufficient_evidence verdict regardless of the raw lift.
     */
    private const MIN_SAMPLE_SIZE = 5;

    /**
     * Verdict when the current window does not carry enough operator-validated
     * decisions to measure leverage honestly.
     */
    private const VERDICT_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /**
     * Verdict when leverage is measured but a gate blocks it (non-positive lift
     * or any decision outside the proven boundary).
     */
    private const VERDICT_BLOCK = 'block';

    /**
     * Verdict when leverage is measured, positive and fully inside the proven
     * boundary.
     */
    private const VERDICT_PASS = 'pass';

    /**
     * Below this absolute value variance is treated as zero, so a missing or
     * empty baseline rate yields a flat (zero) lift instead of a divide-by-zero.
     */
    private const RATE_EPSILON = 1.0e-9;

    /**
     * @param  array{validated_decisions?: mixed, period_units?: mixed, decision_count?: mixed, override_count?: mixed, out_of_bound_count?: mixed}  $baseline
     * @param  array{validated_decisions?: mixed, period_units?: mixed, decision_count?: mixed, override_count?: mixed, out_of_bound_count?: mixed}  $current
     * @return array{
     *     schema_version: string,
     *     throughput_lift: float,
     *     override_rate_delta: float,
     *     out_of_bound_count: int,
     *     sample_size: int,
     *     verdict: string,
     *     insufficient_evidence: bool,
     *     passed: bool,
     *     blockers: list<string>
     * }
     */
    public function score(array $baseline, array $current): array
    {
        $sampleSize = $this->nonNegativeInt($current['validated_decisions'] ?? 0);
        $outOfBoundCount = $this->nonNegativeInt($current['out_of_bound_count'] ?? 0);

        $baselineRate = $this->throughputRate($baseline);
        $currentRate = $this->throughputRate($current);
        $throughputLift = $this->relativeLift($baselineRate, $currentRate);

        $overrideRateDelta = round(
            $this->overrideRate($current) - $this->overrideRate($baseline),
            4,
        );

        $insufficientEvidence = $sampleSize < self::MIN_SAMPLE_SIZE;

        $blockers = [];
        if (! $insufficientEvidence) {
            // Ordered gates: lift first, then proven-boundary breaches.
            if ($throughputLift <= 0.0) {
                $blockers[] = 'non_positive_throughput_lift';
            }

            if ($outOfBoundCount > 0) {
                $blockers[] = 'out_of_bound_decisions_present';
            }
        }

        if ($insufficientEvidence) {
            $verdict = self::VERDICT_INSUFFICIENT_EVIDENCE;
        } elseif ($blockers !== []) {
            $verdict = self::VERDICT_BLOCK;
        } else {
            $verdict = self::VERDICT_PASS;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'throughput_lift' => $throughputLift,
            'override_rate_delta' => $overrideRateDelta,
            'out_of_bound_count' => $outOfBoundCount,
            'sample_size' => $sampleSize,
            'verdict' => $verdict,
            'insufficient_evidence' => $insufficientEvidence,
            'passed' => $verdict === self::VERDICT_PASS,
            'blockers' => $blockers,
        ];
    }

    /**
     * Operator-validated throughput rate of a window: validated decisions per
     * unit of operator effort. A non-positive period yields a zero rate.
     *
     * @param  array{validated_decisions?: mixed, period_units?: mixed}  $window
     */
    private function throughputRate(array $window): float
    {
        $validated = (float) $this->nonNegativeInt($window['validated_decisions'] ?? 0);
        $period = $this->nonNegativeFloat($window['period_units'] ?? 0);

        if ($period <= self::RATE_EPSILON) {
            return 0.0;
        }

        return $validated / $period;
    }

    /**
     * Relative lift of the current rate over the baseline rate. When the
     * baseline rate is effectively zero the lift is reported as flat (0.0)
     * rather than diverging, keeping the value finite and comparable.
     */
    private function relativeLift(float $baselineRate, float $currentRate): float
    {
        if ($baselineRate <= self::RATE_EPSILON) {
            return 0.0;
        }

        return round(($currentRate - $baselineRate) / $baselineRate, 4);
    }

    /**
     * Override rate of a window in 0..1: operator overrides per delegated
     * decision. No delegated decisions yields a zero override rate.
     *
     * @param  array{decision_count?: mixed, override_count?: mixed}  $window
     */
    private function overrideRate(array $window): float
    {
        $decisions = $this->nonNegativeInt($window['decision_count'] ?? 0);
        if ($decisions === 0) {
            return 0.0;
        }

        $overrides = min($this->nonNegativeInt($window['override_count'] ?? 0), $decisions);

        return $overrides / $decisions;
    }

    private function nonNegativeInt(mixed $raw): int
    {
        if (is_int($raw)) {
            return max($raw, 0);
        }

        if (is_float($raw)) {
            // A non-finite (NAN/INF) or non-positive float carries no proven count.
            if (! is_finite($raw) || $raw <= 0.0) {
                return 0;
            }

            // A float at or beyond 2^63 is not representable as an int: casting it
            // would emit a runtime warning and overflow to a platform-dependent
            // (even negative) value, breaking purity and determinism. Clamp such a
            // magnitude to PHP_INT_MAX so the count stays a deterministic, large
            // non-negative int.
            if ($raw >= 9223372036854775808.0) {
                return PHP_INT_MAX;
            }

            return (int) $raw;
        }

        return 0;
    }

    private function nonNegativeFloat(mixed $raw): float
    {
        if (is_int($raw) || is_float($raw)) {
            return max((float) $raw, 0.0);
        }

        return 0.0;
    }
}
