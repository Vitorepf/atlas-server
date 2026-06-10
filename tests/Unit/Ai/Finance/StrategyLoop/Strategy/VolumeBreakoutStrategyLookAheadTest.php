<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\VolumeBreakoutStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the volume-confirmed breakout engine cannot look ahead (prefix-invariance),
 * that the inert baseline trades zero (anti-fake contract), and — the family's whole
 * reason to exist — that the VOLUME GATE actually gates: the same price path with
 * flat volume must produce zero entries, while abnormal breakout volume enters.
 */
final class VolumeBreakoutStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new VolumeBreakoutStrategy)->run($this->series(60, withVolumeSpike: true), $this->params());
        $prefix = (new VolumeBreakoutStrategy)->run($this->series(40, withVolumeSpike: true), $this->params());

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
        $r = (new VolumeBreakoutStrategy)->run($this->series(60, withVolumeSpike: true), $this->params(0.0));

        $this->assertSame(0, $r->nTrades);
        foreach ($r->equityCurve as $e) {
            $this->assertEqualsWithDelta(1.0, $e, 1e-12, 'inert baseline must hold flat equity');
        }
    }

    public function test_breakout_with_abnormal_volume_enters(): void
    {
        $r = (new VolumeBreakoutStrategy)->run($this->series(60, withVolumeSpike: true), $this->params(0.5));

        $this->assertGreaterThanOrEqual(1, $r->nTrades, 'breakout confirmed by abnormal volume should enter');
        $this->assertGreaterThan(19, (int) $r->trades[0]['entry_idx'], 'entry should land in the trend, not the chop');
    }

    public function test_same_breakout_with_flat_volume_is_rejected(): void
    {
        $r = (new VolumeBreakoutStrategy)->run($this->series(60, withVolumeSpike: false), $this->params(0.5));

        $this->assertSame(0, $r->nTrades, 'the volume gate must reject a low-participation breakout');
    }

    /** @return array<string,float|int> */
    private function params(float $risk = 0.5): array
    {
        return [
            'regime_period' => 10, 'entry_lookback' => 10, 'exit_lookback' => 10,
            'atr_period' => 5, 'atr_mult' => 3.0, 'vol_sma_period' => 10, 'vol_mult' => 2.0,
            'risk_pct' => $risk, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Same deterministic path as the trend-breakout fixture (chop ~100 → uptrend to 140
     * → decline), with volume flat at 1000 except — when $withVolumeSpike — 5× spikes on
     * the early uptrend bars where the Donchian breakout fires.
     *
     * @return list<Bar>
     */
    private function series(int $len, bool $withVolumeSpike): array
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
            $volume = ($withVolumeSpike && $i >= 20 && $i <= 27) ? 5000.0 : 1000.0;
            $bars[] = new Bar($i * $day, $open, $hi, $lo, $price, $volume, ($i + 1) * $day - 1);
            $prev = $price;
        }

        return $bars;
    }
}
