<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Numeric;

/**
 * O-2 slice (c): the DETERMINISTIC numeric-safety primitive the kernel family needs.
 *
 * The NaN/INF/overflow/SORT_STRING family recurs across the scoring kernels and — per
 * hard evidence — does NOT converge via stochastic loop rounds: each round patches one
 * call-site while the same shape reappears elsewhere. The fix is ONE reusable guard with
 * a frozen contract, adopted at the kernel boundary, so any new kernel is born covered.
 *
 * Every method is total (never throws, never returns NaN/INF) and pure. The contract:
 *   - finite() collapses NaN/±INF/non-numeric to a caller default;
 *   - safeDivide() never divides by zero;
 *   - clamp() bounds a value into [min,max] (and is finite-safe);
 *   - safeMean()/safeSum() are overflow- and NaN-resistant;
 *   - numericSort() sorts numbers NUMERICALLY (never the SORT_STRING trap "10" < "9").
 */
final class NumericSafetyGuard
{
    /** Collapse NaN, ±INF and non-numeric input to $default; otherwise return the float. */
    public static function finite(mixed $value, float $default = 0.0): float
    {
        if (! is_int($value) && ! is_float($value)) {
            if (! is_numeric($value)) {
                return $default;
            }
            $value = (float) $value;
        }
        $f = (float) $value;

        return is_finite($f) ? $f : $default;
    }

    /** Division that never throws and never yields NaN/INF: 0 (or $whenZero) on a 0 divisor. */
    public static function safeDivide(mixed $numerator, mixed $denominator, float $whenZero = 0.0): float
    {
        $d = self::finite($denominator, 0.0);
        if ($d === 0.0) {
            return self::finite($whenZero, 0.0);
        }

        return self::finite(self::finite($numerator, 0.0) / $d, $whenZero);
    }

    /** Bound a finite value into [$min,$max]. Tolerates swapped bounds and non-finite input. */
    public static function clamp(mixed $value, float $min, float $max, float $default = 0.0): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        $v = self::finite($value, $default);

        return max($min, min($max, $v));
    }

    /**
     * Mean that ignores non-finite entries and never divides by zero.
     *
     * @param  iterable<mixed>  $values
     */
    public static function safeMean(iterable $values, float $whenEmpty = 0.0): float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($values as $v) {
            if (! is_numeric($v) && ! is_int($v) && ! is_float($v)) {
                continue;
            }
            $f = (float) $v;
            if (! is_finite($f)) {
                continue;
            }
            $sum += $f;
            $n++;
        }

        return $n === 0 ? $whenEmpty : self::finite($sum / $n, $whenEmpty);
    }

    /**
     * Sum of finite entries (non-finite skipped), result itself finite-guarded.
     *
     * @param  iterable<mixed>  $values
     */
    public static function safeSum(iterable $values): float
    {
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += self::finite($v, 0.0);
        }

        return self::finite($sum, 0.0);
    }

    /**
     * Sort numerically ascending — the cure for the SORT_STRING trap where "10" sorts
     * before "9". Non-numeric entries collapse to 0. Returns a new re-indexed list.
     *
     * @param  iterable<mixed>  $values
     * @return list<float>
     */
    public static function numericSort(iterable $values, bool $descending = false): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = self::finite($v, 0.0);
        }
        usort($out, $descending
            ? static fn (float $a, float $b): int => $b <=> $a
            : static fn (float $a, float $b): int => $a <=> $b);

        return $out;
    }
}
