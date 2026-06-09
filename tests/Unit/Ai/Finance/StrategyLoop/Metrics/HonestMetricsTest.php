<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Metrics;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Pins the per-candidate inner-loop reductions that REMAIN in PHP after R7.3
 * (mean, sample std, per-period & annualized Sharpe, max drawdown). These are the
 * cheap O(n) reductions the strategy search calls per parameterization; they carry
 * no audit-sensitive formula.
 *
 * The SENSITIVE statistical engine that used to live here — return moments
 * (skewness/kurtosis), the Deflated Sharpe with the Lo-2002 variance floor +
 * normal CDF/inverse, and PBO via CSCV — has been migrated to the REAL Python
 * numpy runtime (runtimes/python/honest_metrics) behind HonestMetricsRuntimeClient.
 * Its correctness AND its behavioural equivalence to the removed PHP (every field
 * within 1e-9, including the varSharpe=0 / clustered-siblings DSR N-sweep the audit
 * pinned) are proven in:
 *   - runtimes/python/honest_metrics/tests/test_metrics.py        (known answers)
 *   - runtimes/python/honest_metrics/tests/test_php_equivalence.py (old-vs-new pins)
 *   - tests/Feature/Ai/RuntimeBoundary/HonestMetricsRuntimeClientTest.php (the boundary)
 */
final class HonestMetricsTest extends TestCase
{
    private HonestMetrics $m;

    protected function setUp(): void
    {
        $this->m = new HonestMetrics;
    }

    public function test_mean_and_std(): void
    {
        $this->assertEqualsWithDelta(2.5, $this->m->mean([1, 2, 3, 4]), 1e-12);
        $this->assertEqualsWithDelta(2.1380, $this->m->std([2, 4, 4, 4, 5, 5, 7, 9]), 1e-3);
        $this->assertSame(0.0, $this->m->mean([]));            // empty -> 0
        $this->assertSame(0.0, $this->m->std([5.0]));          // n<2 sample -> 0
        $this->assertSame(0.0, $this->m->std([7.0], false));   // population, n=1, no spread
    }

    public function test_per_period_and_annualized_sharpe(): void
    {
        // returns with a clean per-period Sharpe; annualized = pp * sqrt(ppy).
        $r = [0.01, 0.02, 0.01, 0.03, 0.02, 0.01, 0.02, 0.01];
        $pp = $this->m->perPeriodSharpe($r);
        $this->assertGreaterThan(0.0, $pp);
        $this->assertEqualsWithDelta($pp * sqrt(365.0), $this->m->sharpe($r, 365), 1e-12);
        $this->assertSame(0.0, $this->m->perPeriodSharpe([0.0, 0.0, 0.0, 0.0])); // zero std -> 0
        $this->assertSame(0.0, $this->m->sharpe([0.01], 365));                   // n=1 std 0 -> 0
        // ppy floors at 1.0 inside sqrt(max(1, ppy)):
        $this->assertEqualsWithDelta($pp, $this->m->sharpe($r, 0.0), 1e-12);
    }

    public function test_max_drawdown(): void
    {
        $this->assertEqualsWithDelta(0.3333, $this->m->maxDrawdown([1.0, 1.2, 0.9, 1.0, 0.8]), 1e-3);
        $this->assertEqualsWithDelta(0.0, $this->m->maxDrawdown([1.0, 1.1, 1.2, 1.3]), 1e-12); // monotonic up
    }

    public function test_max_drawdown_flags_non_finite_equity(): void
    {
        $this->assertSame(1.0, $this->m->maxDrawdown([1.0, INF, 0.5]));
        $this->assertSame(1.0, $this->m->maxDrawdown([1.0, NAN, 0.5]));
    }
}
