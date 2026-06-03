<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the strategy engine cannot look ahead, via prefix-invariance: the equity and
 * returns for bars [0..39] must be identical whether or not the future bars [40..59]
 * exist. A strategy that peeked at its own future would produce different early
 * outputs once the future is appended. Also pins that the inert baseline trades zero
 * (so reverting any candidate to baseline goes RED — the anti-fake contract).
 */
final class TrendBreakoutStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new TrendBreakoutStrategy)->run($this->series(60), $this->params());
        $prefix = (new TrendBreakoutStrategy)->run($this->series(40), $this->params());

        $this->assertCount(40, $prefix->equityCurve);
        $this->assertGreaterThanOrEqual(40, count($full->equityCurve));

        for ($i = 0; $i < 40; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — look-ahead leak");
        }
        for ($i = 0; $i < 39; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} — look-ahead leak");
        }
    }

    public function test_inert_baseline_makes_no_trades(): void
    {
        $r = (new TrendBreakoutStrategy)->run($this->series(60), $this->params(0.0));

        $this->assertSame(0, $r->nTrades);
        foreach ($r->equityCurve as $e) {
            $this->assertEqualsWithDelta(1.0, $e, 1e-12, 'inert baseline must hold flat equity');
        }
    }

    public function test_uptrend_produces_a_long_trade(): void
    {
        $r = (new TrendBreakoutStrategy)->run($this->series(60), $this->params(0.5));

        $this->assertGreaterThanOrEqual(1, $r->nTrades, 'a clear uptrend should trigger at least one long');
        $this->assertGreaterThan(19, (int) $r->trades[0]['entry_idx'], 'entry should land in the trend, not the chop');
    }

    /** @return array<string,float|int> */
    private function params(float $risk = 0.5): array
    {
        return [
            'regime_period' => 10, 'entry_lookback' => 10, 'exit_lookback' => 10,
            'atr_period' => 5, 'atr_mult' => 3.0, 'risk_pct' => $risk,
            'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Deterministic path: chop ~100, then a clean uptrend to 140, then a decline. The
     * trend triggers a breakout long; the decline triggers the exit.
     *
     * @return list<Bar>
     */
    private function series(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 20) {
                $prices[] = 100.0 + ($i % 2) * 0.5;        // chop
            } elseif ($i < 40) {
                $prices[] = 100.0 + ($i - 19) * 2.0;       // uptrend 102 -> 140
            } else {
                $prices[] = 140.0 - ($i - 39) * 1.5;       // decline
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
