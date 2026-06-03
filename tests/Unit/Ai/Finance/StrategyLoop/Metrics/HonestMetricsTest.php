<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Metrics;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Pins the honest scorer against closed-form / hand-computed cases. The two that matter
 * most for the loop's integrity:
 *   - the Deflated Sharpe FALLS as the trial count rises (anti-Goodhart: searching
 *     harder must make the bar higher), and
 *   - PBO is ~0 for a genuinely dominant strategy but ~1 for a regime-flip overfit.
 */
final class HonestMetricsTest extends TestCase
{
    private HonestMetrics $m;

    protected function setUp(): void
    {
        $this->m = new HonestMetrics;
    }

    public function test_mean_std_skew_kurtosis(): void
    {
        $this->assertEqualsWithDelta(2.5, $this->m->mean([1, 2, 3, 4]), 1e-12);
        $this->assertEqualsWithDelta(2.1380, $this->m->std([2, 4, 4, 4, 5, 5, 7, 9]), 1e-3);
        $this->assertEqualsWithDelta(0.0, $this->m->skewness([-2, -1, 0, 1, 2]), 1e-9); // symmetric
        $this->assertGreaterThan(0.0, $this->m->kurtosis([-2, -1, 0, 1, 2]));
    }

    public function test_max_drawdown(): void
    {
        $this->assertEqualsWithDelta(0.3333, $this->m->maxDrawdown([1.0, 1.2, 0.9, 1.0, 0.8]), 1e-3);
        $this->assertEqualsWithDelta(0.0, $this->m->maxDrawdown([1.0, 1.1, 1.2, 1.3]), 1e-12); // monotonic up
    }

    public function test_normal_cdf_and_inverse(): void
    {
        $this->assertEqualsWithDelta(0.5, $this->m->normalCdf(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.975, $this->m->normalCdf(1.95996), 2e-3);
        $this->assertEqualsWithDelta(1.95996, $this->m->inverseNormalCdf(0.975), 1e-3);
        $this->assertEqualsWithDelta(0.0, $this->m->inverseNormalCdf(0.5), 1e-9);
    }

    public function test_deflated_sharpe_falls_as_trials_rise(): void
    {
        $oneTrial = $this->m->deflatedSharpe(0.1, 500, 0.0, 3.0, 1, 0.04);
        $manyTrials = $this->m->deflatedSharpe(0.1, 500, 0.0, 3.0, 100, 0.04);

        $this->assertGreaterThan($manyTrials, $oneTrial, 'more trials must lower the DSR');
        foreach ([$oneTrial, $manyTrials] as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThanOrEqual(1.0, $v);
        }
    }

    public function test_deflated_sharpe_strong_vs_weak(): void
    {
        // strong edge, few trials -> confident
        $strong = $this->m->deflatedSharpe(0.20, 1000, 0.0, 3.0, 1, 0.0);
        $this->assertGreaterThan(0.95, $strong);

        // weak edge, heavy search -> not significant
        $weak = $this->m->deflatedSharpe(0.02, 300, 0.0, 3.0, 200, 0.04);
        $this->assertLessThan(0.5, $weak);
    }

    public function test_floor_makes_n_bite_even_with_zero_sibling_variance(): void
    {
        // THE audit regression: the convergent loop's natural state is clustered/identical
        // sibling Sharpes => observed variance 0. Before the analytic floor, N was inert and a
        // marginal no-edge winner certified at any N. Now N must still raise the bar.
        $n2 = $this->m->deflatedSharpe(0.0717, 2303, 0.6, 14.0, 2, 0.0);
        $n300 = $this->m->deflatedSharpe(0.0717, 2303, 0.6, 14.0, 300, 0.0);

        $this->assertGreaterThan($n300, $n2, 'N must still bite at zero observed sibling variance');
        $this->assertLessThan(0.95, $n300, 'a marginal winner must NOT certify after a 300-way search');
    }

    public function test_pbo_rejects_a_non_finite_cell(): void
    {
        $matrix = [
            [0.03, 0.02, 0.03, 0.02, 0.03, 0.02, 0.03, NAN], // a single NaN must not become "best"
            [0.01, 0.01, 0.01, 0.01, 0.01, 0.01, 0.01, 0.01],
        ];

        $this->assertSame(1.0, $this->m->pbo($matrix, 8)); // worst: cannot disprove overfitting
    }

    public function test_max_drawdown_flags_non_finite_equity(): void
    {
        $this->assertSame(1.0, $this->m->maxDrawdown([1.0, INF, 0.5]));
        $this->assertSame(1.0, $this->m->maxDrawdown([1.0, NAN, 0.5]));
    }

    public function test_deflated_sharpe_fails_safe_on_degenerate_higher_moments(): void
    {
        // extreme skew/kurtosis drive the Sharpe-estimator variance non-positive: must REJECT (0.0),
        // never certify (the old floor-to-1e-12 made z -> +INF -> DSR 1.0).
        $this->assertSame(0.0, $this->m->deflatedSharpe(0.2, 750, 8.0, 3.0, 6, 0.001));
    }

    public function test_pbo_is_low_for_a_dominant_strategy(): void
    {
        $matrix = [
            [0.03, 0.02, 0.03, 0.02, 0.03, 0.02, 0.03, 0.02], // always best
            [-0.01, -0.02, -0.01, -0.02, -0.01, -0.02, -0.01, -0.02],
            [0.005, 0.004, 0.005, 0.004, 0.005, 0.004, 0.005, 0.004],
        ];

        $this->assertLessThan(0.2, $this->m->pbo($matrix, 8));
    }

    public function test_pbo_is_high_for_a_regime_flip_overfit(): void
    {
        // each strategy wins one half and loses the other -> the IS winner is the OOS loser.
        $matrix = [
            [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
            [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
        ];

        $this->assertGreaterThan(0.5, $this->m->pbo($matrix, 8));
    }
}
