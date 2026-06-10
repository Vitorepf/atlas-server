<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendPullbackStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the pullback-in-trend engine cannot look ahead (prefix-invariance over a
 * path that actually contains a dip, so trades occur inside the compared window),
 * that the inert baseline trades zero, and that the family does what its hypothesis
 * says: it buys the DIP inside an uptrend — not the chop, not the breakout.
 */
final class TrendPullbackStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new TrendPullbackStrategy)->run($this->series(60), $this->params());
        $prefix = (new TrendPullbackStrategy)->run($this->series(42), $this->params());

        $this->assertCount(42, $prefix->equityCurve);
        $this->assertGreaterThanOrEqual(42, count($full->equityCurve));

        for ($i = 0; $i < 42; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — look-ahead leak");
        }
        for ($i = 0; $i < 41; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} — look-ahead leak");
        }
    }

    public function test_inert_baseline_makes_no_trades(): void
    {
        $r = (new TrendPullbackStrategy)->run($this->series(60), $this->params(0.0));

        $this->assertSame(0, $r->nTrades);
        foreach ($r->equityCurve as $e) {
            $this->assertEqualsWithDelta(1.0, $e, 1e-12, 'inert baseline must hold flat equity');
        }
    }

    public function test_buys_the_dip_inside_the_uptrend(): void
    {
        $r = (new TrendPullbackStrategy)->run($this->series(60), $this->params(0.5));

        $this->assertGreaterThanOrEqual(1, $r->nTrades, 'a dip inside a clear uptrend should trigger an entry');
        $entry = (int) $r->trades[0]['entry_idx'];
        $this->assertGreaterThanOrEqual(35, $entry, 'entry must land in the dip, not the chop/breakout');
        $this->assertLessThanOrEqual(45, $entry, 'entry must land in the dip window');
    }

    /** @return array<string,float|int> */
    private function params(float $risk = 0.5): array
    {
        return [
            'trend_period' => 10, 'pullback_period' => 8, 'pullback_atr' => 0.8,
            'atr_period' => 5, 'atr_mult' => 3.0, 'max_hold_bars' => 20,
            'risk_pct' => $risk, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Deterministic path with the family's exact habitat: chop ~100 (bars 0-14), clean
     * uptrend to 140 (15-34), a DIP to ~132.5 (35-39), then resumption (40+). The dip
     * inside the still-up regime is where the entry must fire; resumption provides the
     * target exit.
     *
     * @return list<Bar>
     */
    private function series(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 15) {
                $prices[] = 100.0 + ($i % 2) * 0.5;          // chop
            } elseif ($i < 35) {
                $prices[] = 100.0 + ($i - 14) * 2.0;         // uptrend 102 -> 140
            } elseif ($i < 40) {
                $prices[] = 140.0 - ($i - 34) * 1.5;         // dip 138.5 -> 132.5
            } else {
                $prices[] = 132.5 + ($i - 39) * 2.0;         // resumption
            }
        }

        $bars = [];
        $day = 86_400_000;
        $prev = $prices[0];
        foreach ($prices as $i => $price) {
            $open = $i === 0 ? $price : $prev;
            $hi = max($open, $price) * 1.005;
            $lo = min($open, $price) * 0.995;
            $bars[] = new Bar($i * $day, $open, $hi, $lo, $price, 1000.0, ($i + 1) * $day - 1);
            $prev = $price;
        }

        return $bars;
    }
}
