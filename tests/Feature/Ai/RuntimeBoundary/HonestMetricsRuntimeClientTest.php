<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the PHP kernel really invokes the Python honest-finance-metrics runtime
 * and gets REAL numpy metrics back through the boundary — not a PHP hand-rolled
 * stand-in. Gated on the runtime being set up (scripts/setup-honest-metrics-runtime.sh);
 * when absent the e2e tests skip honestly and the anti-fallback test asserts an
 * explicit failure (R7.3 removed the PHP math — there is no silent fallback).
 *
 * The DSR known-answers below are the EXACT values the (now-removed) PHP produced,
 * captured at git HEAD and proven equal within 1e-9 — including the SENSITIVE
 * varSharpe=0 / clustered-siblings Lo-2002-floor case the audit pinned. A fake or a
 * drifted engine fails them.
 */
final class HonestMetricsRuntimeClientTest extends TestCase
{
    public function test_php_invokes_real_python_metrics_end_to_end(): void
    {
        $client = new HonestMetricsRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('honest_metrics runtime not set up — honest skip (not a fake).');
        }

        // moments known-answer: mean of [1,2,3,4] is exactly 2.5 (a stub fails this).
        $moments = $client->moments([1.0, 2.0, 3.0, 4.0]);
        $this->assertEqualsWithDelta(2.5, $moments['mean'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $moments['skewness'], 1e-9); // symmetric

        // DSR strong edge, few trials -> confident (> 0.95).
        $strong = $client->deflatedSharpe(0.20, 1000, 0.0, 3.0, 1, 0.0);
        $this->assertGreaterThan(0.95, $strong['deflated_sharpe']);

        // PBO regime-flip overfit -> high (> 0.5).
        $pbo = $client->pbo([
            [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
            [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
        ], 8);
        $this->assertGreaterThan(0.5, $pbo['pbo']);
    }

    public function test_lo2002_floor_curve_matches_removed_php_within_1e_9(): void
    {
        $client = new HonestMetricsRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('honest_metrics runtime not set up — honest skip (not a fake).');
        }

        // THE audit regression, varSharpe=0 / clustered siblings: the N-sweep must
        // FALL and match the OLD PHP bit-for-bit within 1e-9.
        $n1 = $client->deflatedSharpe(0.0717, 2303, 0.6, 14.0, 1, 0.0)['deflated_sharpe'];
        $n300 = $client->deflatedSharpe(0.0717, 2303, 0.6, 14.0, 300, 0.0)['deflated_sharpe'];
        $n100k = $client->deflatedSharpe(0.0717, 2303, 0.6, 14.0, 100000, 0.0)['deflated_sharpe'];

        $this->assertEqualsWithDelta(0.99975505820821908, $n1, 1e-9);
        $this->assertEqualsWithDelta(0.70842280348991626, $n300, 1e-9);
        $this->assertEqualsWithDelta(0.16646455903015678, $n100k, 1e-9);
        $this->assertGreaterThan($n300, $n1);
        $this->assertGreaterThan($n100k, $n300);

        // fail-safe on degenerate higher moments -> exactly 0.0 (never certify).
        $this->assertSame(0.0, $client->deflatedSharpe(0.2, 750, 8.0, 3.0, 6, 0.001)['deflated_sharpe']);
    }

    public function test_honesty_gate_bundle_in_one_subprocess(): void
    {
        $client = new HonestMetricsRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('honest_metrics runtime not set up — honest skip (not a fake).');
        }

        $bundle = $client->honestyGate(
            winnerDailyReturns: array_map(static fn (int $i): float => 0.001 * ($i % 7 - 3), range(0, 499)),
            siblingSharpes: [0.108, 0.110, 0.112],
            siblingWindows: [array_fill(0, 16, 0.03), array_fill(0, 16, 0.02), array_fill(0, 16, 0.01)],
            scenariosExplored: 47,
        );

        $this->assertSame(47, $bundle['n_trials']);
        // var_sharpe = std([0.108,0.110,0.112])^2 = 0.000004 exactly.
        $this->assertEqualsWithDelta(4.0e-6, $bundle['var_sharpe_across_trials'], 1e-12);
        $this->assertGreaterThanOrEqual(0.0, $bundle['deflated_sharpe']);
        $this->assertLessThanOrEqual(1.0, $bundle['deflated_sharpe']);
        $this->assertLessThan(0.2, $bundle['pbo']); // row0 dominates -> low PBO
    }

    public function test_runtime_absence_fails_explicitly_never_a_silent_fake(): void
    {
        $client = new HonestMetricsRuntimeClient;
        if ($client->available()) {
            // Runtime present: the e2e tests cover the anti-fake boundary guard.
            $this->assertTrue(true);

            return;
        }
        $this->expectException(RuntimeException::class);
        $client->moments([1.0, 2.0, 3.0]);
    }
}
