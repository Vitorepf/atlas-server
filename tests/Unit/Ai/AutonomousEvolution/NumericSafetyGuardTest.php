<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Numeric\NumericSafetyGuard as G;
use PHPUnit\Framework\TestCase;

/**
 * O-2 slice (c): the deterministic numeric guard neutralizes the EXACT recurring family
 * (NaN, ±INF, divide-by-zero, overflow, SORT_STRING) — one primitive, frozen contract.
 */
final class NumericSafetyGuardTest extends TestCase
{
    public function test_finite_collapses_nan_inf_and_non_numeric(): void
    {
        $this->assertSame(0.0, G::finite(NAN));
        $this->assertSame(0.0, G::finite(INF));
        $this->assertSame(0.0, G::finite(-INF));
        $this->assertSame(0.0, G::finite('not a number'));
        $this->assertSame(-7.5, G::finite(NAN, -7.5));
        $this->assertSame(3.0, G::finite('3'));
        $this->assertSame(3.5, G::finite(3.5));
    }

    public function test_safe_divide_never_yields_nan_or_inf(): void
    {
        $this->assertSame(0.0, G::safeDivide(5, 0));
        $this->assertSame(-1.0, G::safeDivide(5, 0, -1.0));
        $this->assertSame(0.0, G::safeDivide(0, 0));
        $this->assertSame(2.5, G::safeDivide(5, 2));
        $this->assertSame(0.0, G::safeDivide(INF, 1)); // INF numerator collapses
    }

    public function test_clamp_bounds_and_tolerates_swapped_bounds_and_non_finite(): void
    {
        $this->assertSame(10.0, G::clamp(99, 0, 10));
        $this->assertSame(0.0, G::clamp(-5, 0, 10));
        $this->assertSame(5.0, G::clamp(5, 0, 10));
        $this->assertSame(10.0, G::clamp(99, 10, 0)); // swapped bounds tolerated
        $this->assertSame(0.0, G::clamp(NAN, 0, 10)); // NaN -> default, then clamped
    }

    public function test_safe_mean_and_sum_ignore_non_finite_and_empty(): void
    {
        $this->assertSame(2.0, G::safeMean([1, 2, 3]));
        $this->assertSame(2.0, G::safeMean([1, NAN, 3])); // NaN entry skipped -> (1+3)/2
        $this->assertSame(0.0, G::safeMean([]));
        $this->assertSame(-1.0, G::safeMean([NAN, INF], -1.0)); // all non-finite -> empty
        $this->assertSame(6.0, G::safeSum([1, 2, 3, INF])); // INF entry skipped
    }

    public function test_numeric_sort_cures_the_sort_string_trap(): void
    {
        // The classic bug: string sort puts "10" before "9". numericSort must not.
        $this->assertSame([2.0, 9.0, 10.0, 100.0], G::numericSort(['10', '2', '100', '9']));
        $this->assertSame([100.0, 10.0, 9.0, 2.0], G::numericSort(['10', '2', '100', '9'], descending: true));
        $this->assertSame([0.0, 1.0], G::numericSort([NAN, 1])); // NaN -> 0.0, sorted
    }
}
