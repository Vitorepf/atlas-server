<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop;

use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient;
use Tests\TestCase;

/**
 * Proves the honesty gate closes the loop's #1 overfitting risk — INCLUDING the regime the
 * adversarial audit exploited: when the passing siblings CLUSTER (the loop's natural
 * convergent state), the empirical cross-trial variance is ~0. The analytic variance floor
 * must still make the trial count N bite, so the same clustered-sibling winner certifies at
 * N=2 but is rejected at N=500. Siblings are fed in PER-PERIOD units (as production now does).
 *
 * R7.3: the DSR/PBO/variance engine now runs in the REAL Python numpy runtime behind the
 * boundary, so these are end-to-end gate-through-Python tests. They are gated on the runtime
 * being set up (scripts/setup-honest-metrics-runtime.sh) and skip honestly when absent — the
 * canon forbids a PHP fallback, so there is nothing to test in-process without it. The
 * old-vs-new numeric equivalence (the same floor curve, within 1e-9) is pinned in
 * runtimes/python/honest_metrics/tests/test_php_equivalence.py.
 */
final class TradingHonestyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! (new HonestMetricsRuntimeClient)->available()) {
            $this->markTestSkipped('honest_metrics runtime not set up — honest skip (the gate has no PHP fallback math).');
        }
    }

    /** A clustered-sibling base — the convergent regime. The floor must still deflate by N. */
    private function base(): array
    {
        return [
            'winner_daily_returns' => $this->binaryReturns(555, 445), // per-period Sharpe ~0.11
            'sibling_windows' => [array_fill(0, 16, 0.03), array_fill(0, 16, 0.02), array_fill(0, 16, 0.01)], // row0 dominates -> PBO~0
            'sibling_sharpes' => [0.108, 0.110, 0.112], // 3 distinct but CLUSTERED, per-period (var ~1e-5)
            'holdout_sharpe' => 0.6,  // annualized, above the 0.5 significance floor
            'holdout_trades' => 15,   // above the 10-trade floor
        ];
    }

    public function test_trial_count_injection_flips_even_with_clustered_siblings(): void
    {
        $gate = new TradingHonestyGate;

        $small = $gate->evaluate($this->base() + ['scenarios_explored' => 2]);
        $wide = $gate->evaluate($this->base() + ['scenarios_explored' => 500]);

        $this->assertTrue($small['certified'], 'N=2 should certify: '.json_encode($small['reasons']));
        $this->assertFalse($wide['certified'], 'N=500 must reject the SAME clustered-sibling candidate — the audit exploit');
        $this->assertStringContainsString('deflated_sharpe_too_low', implode(',', $wide['reasons']));
        $this->assertSame(500, $wide['report']['n_trials']);
        $this->assertLessThan($small['report']['deflated_sharpe'], $wide['report']['deflated_sharpe']);
    }

    public function test_rejects_singleton_or_low_diversity_siblings(): void
    {
        $input = $this->base();
        $input['sibling_sharpes'] = [0.11]; // singleton — the audit's certify-anything regime
        $input['scenarios_explored'] = 50;

        $v = (new TradingHonestyGate)->evaluate($input);

        $this->assertFalse($v['certified']);
        $this->assertStringContainsString('insufficient_sibling_diversity', implode(',', $v['reasons']));
    }

    public function test_rejects_regime_flip_overfit_via_pbo(): void
    {
        $input = $this->base() + ['scenarios_explored' => 3];
        $input['sibling_windows'] = [
            [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
            [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
        ];

        $v = (new TradingHonestyGate)->evaluate($input);

        $this->assertFalse($v['certified']);
        $this->assertStringContainsString('pbo_too_high', implode(',', $v['reasons']));
    }

    public function test_rejects_an_economically_flat_holdout(): void
    {
        // the audit's exact certified-fake case: a near-zero holdout Sharpe must NOT certify.
        $input = $this->base() + ['scenarios_explored' => 3];
        $input['holdout_sharpe'] = 0.019;

        $v = (new TradingHonestyGate)->evaluate($input);

        $this->assertFalse($v['certified']);
        $this->assertStringContainsString('holdout_not_positive', implode(',', $v['reasons']));
    }

    public function test_certifies_a_genuinely_earned_edge(): void
    {
        $v = (new TradingHonestyGate)->evaluate($this->base() + ['scenarios_explored' => 3]);

        $this->assertTrue($v['certified'], json_encode($v['reasons']));
        $this->assertSame(['certified'], $v['reasons']);
        $this->assertGreaterThan(0.95, $v['report']['deflated_sharpe']);
        $this->assertLessThan(0.2, $v['report']['pbo']);
    }

    /** @return list<float> a binary ±0.01 series with a controlled per-period Sharpe */
    private function binaryReturns(int $pos, int $neg): array
    {
        return [...array_fill(0, $pos, 0.01), ...array_fill(0, $neg, -0.01)];
    }
}
