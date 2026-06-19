<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBenchmarkHarness;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 4 · §11.2 — the benchmark harness PERF-CERT. The statistical math (median/IQR) and the cert
 * logic (threshold + variance guard + ÷0) are pinned DETERMINISTICALLY against the REAL methods (summarize /
 * certify), separated from the noisy wall-clock so each guard is independently load-bearing.
 */
final class AtlasLoopBenchmarkHarnessTest extends TestCase
{
    private AtlasLoopBenchmarkHarness $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h = new AtlasLoopBenchmarkHarness();
    }

    public function test_summarize_pins_the_real_percentile_median_and_iqr(): void
    {
        // Against the HARNESS's own summarize() (not a test copy): linear-interpolated quartiles.
        $s = $this->h->summarize([50, 10, 40, 20, 30]); // unsorted on purpose
        $this->assertSame(5, $s['n']);
        $this->assertSame(30.0, $s['median_ns'], 'median of 10..50');
        $this->assertSame(20.0, $s['iqr_ns'], 'Q3(40) − Q1(20)');
        $this->assertSame(10, $s['min_ns']);
        $this->assertSame(50, $s['max_ns']);

        // 4-sample interpolation: median = midpoint of 20 and 30.
        $this->assertSame(25.0, $this->h->summarize([10, 20, 30, 40])['median_ns']);
    }

    public function test_certify_threshold_guard_is_load_bearing(): void
    {
        // speedup 1.05 (< 1.10 threshold) with a clean variance band ⇒ faster but NOT significant.
        $r = $this->h->certify(['median_ns' => 105.0, 'iqr_ns' => 1.0], ['median_ns' => 100.0, 'iqr_ns' => 1.0], 1.10);
        $this->assertTrue($r['faster']);
        $this->assertEqualsWithDelta(1.05, $r['speedup'], 1e-9);
        $this->assertFalse($r['significant'], 'below the regression threshold ⇒ not significant');
    }

    public function test_certify_variance_guard_is_load_bearing(): void
    {
        // speedup 2.0 (well past threshold) BUT the candidate's median+IQR (100+150=250) reaches past the
        // baseline median (200) ⇒ the noise band overlaps ⇒ NOT significant despite a 2x median speedup.
        $r = $this->h->certify(['median_ns' => 200.0, 'iqr_ns' => 5.0], ['median_ns' => 100.0, 'iqr_ns' => 150.0], 1.10);
        $this->assertEqualsWithDelta(2.0, $r['speedup'], 1e-9);
        $this->assertGreaterThanOrEqual(1.10, $r['speedup']);
        $this->assertFalse($r['significant'], 'variance band overlaps the baseline ⇒ jitter, not a win');

        // Tighten the candidate's IQR so the band clears ⇒ NOW significant (proves the guard is the decider).
        $r2 = $this->h->certify(['median_ns' => 200.0, 'iqr_ns' => 5.0], ['median_ns' => 100.0, 'iqr_ns' => 50.0], 1.10);
        $this->assertTrue($r2['significant'], 'clean variance band + past threshold ⇒ significant');
    }

    public function test_certify_divide_by_zero_branches(): void
    {
        // candidate median 0, baseline > 0 ⇒ INF (infinitely faster), guarded (no division error).
        $this->assertTrue(is_infinite($this->h->certify(['median_ns' => 100.0, 'iqr_ns' => 0.0], ['median_ns' => 0.0, 'iqr_ns' => 0.0])['speedup']));
        // both 0 ⇒ neutral 1.0 (no change), not NaN.
        $this->assertSame(1.0, $this->h->certify(['median_ns' => 0.0, 'iqr_ns' => 0.0], ['median_ns' => 0.0, 'iqr_ns' => 0.0])['speedup']);
    }

    public function test_measure_discards_warmup_and_proves_a_real_speedup(): void
    {
        // n reflects KEPT samples only (warmup discarded) — pins the warmup-discard behavior.
        $work = static function (int $rounds): callable {
            return static function () use ($rounds): int {
                $x = 0;
                for ($i = 0; $i < $rounds * 20000; $i++) {
                    $x += $i;
                }

                return $x;
            };
        };
        $this->assertSame(8, $this->h->measure($work(1), iterations: 8, warmup: 4)['n']);

        // A 6x-work baseline vs 1x candidate is a real, non-flaky margin (deterministic busy loop, not sleep).
        $cert = $this->h->proveSpeedup($work(6), $work(1), iterations: 12);
        $this->assertTrue($cert['faster']);
        $this->assertGreaterThan(1.0, $cert['speedup']);
    }
}
