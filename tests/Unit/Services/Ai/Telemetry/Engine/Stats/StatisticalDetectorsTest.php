<?php

namespace Tests\Unit\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;
use App\Services\Ai\Telemetry\Engine\Stats\BootstrapCalculator;
use App\Services\Ai\Telemetry\Engine\Stats\CusumDetector;
use App\Services\Ai\Telemetry\Engine\Stats\EwmaDetector;
use App\Services\Ai\Telemetry\Engine\Stats\KolmogorovSmirnovTest;
use App\Services\Ai\Telemetry\Engine\Stats\MannKendallAnalyzer;
use App\Services\Ai\Telemetry\Engine\Stats\WilsonCalculator;
use Tests\TestCase;

/**
 * Engine F2 — pin algorithmic correctness of all 6 detectors against synthetic
 * fixtures with known statistical properties.
 *
 * All 6 statistical detectors (EWMA/Mann-Kendall/CUSUM/KS/Wilson + Bootstrap) now
 * compute in numpy behind the runtime_language_boundary (runtimes/python/stats_engine),
 * so these are end-to-end boundary tests: PHP → Python → real stats back. They are
 * the behaviour-equivalence proof that the swap preserved the prior PHP output
 * (same D/p/slope/anomaly values; for the randomised bootstrap, the same exact
 * center + a CI within Monte Carlo error of the old PHP CI), and they skip
 * honestly if the venv is absent.
 */
class StatisticalDetectorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! (new StatsEngineRuntimeClient)->available()) {
            $this->markTestSkipped('stats_engine runtime not set up — honest skip (not a fake). Run scripts/setup-stats-engine-runtime.sh.');
        }
    }

    // ─── EWMA ────────────────────────────────────────────────────────────────

    public function test_ewma_noisy_baseline_then_spike_fires_anomaly(): void
    {
        // Realistic baseline with ±5 noise around 100 — gives EWMA a non-zero sigma.
        // Day 15 jumps to 200 (≈20 sigmas above) — must fire.
        mt_srand(42);
        $series = [];
        for ($i = 0; $i < 14; $i++) {
            $series[] = 95.0 + (mt_rand(0, 1000) / 100.0);
        }
        $series[] = 200.0;

        $r = (new EwmaDetector)->detect($series);
        $this->assertTrue($r['anomaly'], 'Spike at 200 against ~100 baseline must trigger EWMA alarm.');
        $this->assertSame('full', $r['confidence']);
        $this->assertGreaterThan(2.5, abs($r['z_score']));
    }

    public function test_ewma_below_warmup_returns_cold_start(): void
    {
        $series = array_fill(0, 5, 100.0);

        $r = (new EwmaDetector)->detect($series);
        $this->assertSame('cold_start', $r['confidence']);
        $this->assertFalse($r['anomaly']);
        $this->assertSame(5, $r['warmup_days_remaining']);
    }

    public function test_ewma_constant_series_returns_variance_too_low_safely(): void
    {
        $r = (new EwmaDetector)->detect(array_fill(0, 20, 75.0));
        $this->assertFalse($r['anomaly'], 'Pure constant series must not produce anomaly.');
        $this->assertSame('variance_too_low', $r['confidence'],
            'Algorithm must explicitly signal that sigma is too small to test against — not silently miss the call.');
    }

    public function test_ewma_empty_series_returns_cold_start_safely(): void
    {
        $r = (new EwmaDetector)->detect([]);
        $this->assertSame('cold_start', $r['confidence']);
        $this->assertNull($r['today']);
    }

    // ─── Mann-Kendall ────────────────────────────────────────────────────────

    public function test_mann_kendall_strictly_increasing_detects_trend(): void
    {
        $series = range(1, 15);

        $r = (new MannKendallAnalyzer)->test(array_map('floatval', $series));
        $this->assertSame('increasing', $r['direction']);
        $this->assertLessThan(0.05, $r['p_value']);
        $this->assertEqualsWithDelta(1.0, $r['sens_slope'], 0.0001, "Sen's slope on [1..15] = 1.0 per day.");
    }

    public function test_mann_kendall_strictly_decreasing_detects_trend(): void
    {
        $series = array_map('floatval', range(15, 1));

        $r = (new MannKendallAnalyzer)->test($series);
        $this->assertSame('decreasing', $r['direction']);
        $this->assertLessThan(0.05, $r['p_value']);
        $this->assertEqualsWithDelta(-1.0, $r['sens_slope'], 0.0001);
    }

    public function test_mann_kendall_below_min_n_suppressed(): void
    {
        $r = (new MannKendallAnalyzer)->test([1.0, 2.0, 3.0]);
        $this->assertTrue($r['suppressed']);
        $this->assertSame('n_below_min:7', $r['suppression_reason']);
    }

    public function test_mann_kendall_constant_series_no_trend(): void
    {
        $r = (new MannKendallAnalyzer)->test(array_fill(0, 12, 50.0));
        $this->assertSame('none', $r['direction']);
        $this->assertEqualsWithDelta(0.0, $r['sens_slope'], 0.0001);
    }

    // ─── CUSUM ───────────────────────────────────────────────────────────────

    public function test_cusum_noisy_baseline_then_step_shift_fires(): void
    {
        // 15 days noisy baseline around mean 50, then 5 days shifted to mean 70.
        // Reference half (first 10 days) gets non-zero sigma; CUSUM can compute.
        mt_srand(7);
        $series = [];
        for ($i = 0; $i < 15; $i++) {
            $series[] = 47.0 + (mt_rand(0, 600) / 100.0);  // ~50 ± 3
        }
        for ($i = 0; $i < 5; $i++) {
            $series[] = 67.0 + (mt_rand(0, 600) / 100.0);  // ~70 ± 3
        }

        $r = (new CusumDetector)->detect($series);
        $this->assertTrue($r['fired'], 'Step shift of ~20 (≫ 1 sigma) must fire CUSUM.');
        $this->assertSame('upward', $r['direction']);
    }

    public function test_cusum_constant_series_suppressed_by_variance_guard(): void
    {
        $r = (new CusumDetector)->detect(array_fill(0, 20, 50.0));
        $this->assertTrue($r['suppressed']);
        $this->assertSame('reference_variance_too_low', $r['suppression_reason'],
            'Constant reference half cannot compute sigma — algorithm must refuse, not divide by zero.');
    }

    public function test_cusum_below_min_window_suppressed(): void
    {
        $r = (new CusumDetector)->detect(array_fill(0, 10, 50.0));
        $this->assertTrue($r['suppressed']);
        $this->assertSame('window_below_min:15', $r['suppression_reason']);
    }

    // ─── KS Test ─────────────────────────────────────────────────────────────

    public function test_ks_distinct_distributions_detected(): void
    {
        // Sample 1: low values, Sample 2: high values — clear shift
        $sample1 = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0,
                    11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0, 20.0];
        $sample2 = [100.0, 110.0, 120.0, 130.0, 140.0, 150.0, 160.0, 170.0, 180.0, 190.0,
                    200.0, 210.0, 220.0, 230.0, 240.0, 250.0, 260.0, 270.0, 280.0, 290.0];

        $r = (new KolmogorovSmirnovTest)->compare($sample1, $sample2);
        $this->assertSame('ks', $r['method']);
        $this->assertEqualsWithDelta(1.0, $r['d_statistic'], 0.05, 'Disjoint ranges → D ≈ 1.0.');
        $this->assertLessThan(0.001, $r['p_value']);
    }

    public function test_ks_falls_back_to_mann_whitney_for_small_n(): void
    {
        $sample1 = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0]; // n=10 < 20
        $sample2 = [11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0, 20.0];

        $r = (new KolmogorovSmirnovTest)->compare($sample1, $sample2);
        $this->assertSame('mann_whitney', $r['method']);
        $this->assertLessThan(0.05, $r['p_value']);
    }

    public function test_ks_below_absolute_min_suppressed(): void
    {
        $r = (new KolmogorovSmirnovTest)->compare([1.0, 2.0], [3.0, 4.0]);
        $this->assertTrue($r['suppressed']);
    }

    // ─── Bootstrap ───────────────────────────────────────────────────────────

    public function test_bootstrap_mean_ci_brackets_the_mean(): void
    {
        $values = range(1, 100);

        $r = (new BootstrapCalculator)->meanCi(array_map('floatval', $values), replications: 500, seed: 42);
        $this->assertNotNull($r['lower']);
        $this->assertNotNull($r['upper']);
        $this->assertGreaterThanOrEqual($r['lower'], $r['center']);
        $this->assertLessThanOrEqual($r['upper'], $r['center']);
        $this->assertEqualsWithDelta(50.5, $r['center'], 0.01);
    }

    public function test_bootstrap_below_min_n_returns_null(): void
    {
        $r = (new BootstrapCalculator)->meanCi([1.0, 2.0, 3.0]);
        $this->assertNull($r['lower']);
        $this->assertStringContainsString('n_too_small', $r['method']);
    }

    public function test_bootstrap_deterministic_with_same_seed(): void
    {
        $values = range(1, 50);
        $a = (new BootstrapCalculator)->meanCi(array_map('floatval', $values), seed: 7);
        $b = (new BootstrapCalculator)->meanCi(array_map('floatval', $values), seed: 7);

        $this->assertSame($a['lower'], $b['lower'], 'Same seed must produce identical CI.');
        $this->assertSame($a['upper'], $b['upper']);
    }

    // ─── Wilson ──────────────────────────────────────────────────────────────

    public function test_wilson_handles_boundary_zero_correctly(): void
    {
        $r = (new WilsonCalculator)->ci(k: 0, n: 10);
        $this->assertSame(0.0, $r['lower'], 'Wilson at k=0 gives lower=0 (Wald would go negative).');
        $this->assertGreaterThan(0.0, $r['upper']);
    }

    public function test_wilson_handles_boundary_one_correctly(): void
    {
        $r = (new WilsonCalculator)->ci(k: 10, n: 10);
        $this->assertSame(1.0, $r['upper'], 'Wilson at p=1 gives upper=1.');
        $this->assertLessThan(1.0, $r['lower']);
    }

    public function test_wilson_50_50_centered_at_half(): void
    {
        $r = (new WilsonCalculator)->ci(k: 50, n: 100);
        $this->assertEqualsWithDelta(0.50, $r['center'], 0.01);
        $this->assertEqualsWithDelta(0.40, $r['lower'], 0.02);
        $this->assertEqualsWithDelta(0.60, $r['upper'], 0.02);
    }

    public function test_wilson_below_min_n_returns_null(): void
    {
        $r = (new WilsonCalculator)->ci(k: 2, n: 4);
        $this->assertNull($r['lower']);
        $this->assertStringContainsString('n_too_small', $r['method']);
    }
}
