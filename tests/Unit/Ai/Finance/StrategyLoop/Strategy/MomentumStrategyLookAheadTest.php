<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy;
use PHPUnit\Framework\TestCase;

final class MomentumStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new MomentumStrategy)->run($this->series(80), $this->params());
        $prefix = (new MomentumStrategy)->run($this->series(50), $this->params());

        $this->assertCount(50, $prefix->equityCurve);
        $this->assertGreaterThanOrEqual(50, count($full->equityCurve));

        for ($i = 0; $i < 50; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} - look-ahead leak");
        }
        for ($i = 0; $i < 49; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} - look-ahead leak");
        }
    }

    public function test_clear_uptrend_produces_a_long_trade(): void
    {
        $result = (new MomentumStrategy)->run($this->series(80), $this->params());

        $this->assertGreaterThanOrEqual(1, $result->nTrades);
        $this->assertGreaterThanOrEqual(20, (int) $result->trades[0]['entry_idx']);
        $this->assertGreaterThan((int) $result->trades[0]['entry_idx'], (int) $result->trades[0]['exit_idx']);
    }

    /** @return array<string,float|int> */
    private function params(): array
    {
        return [
            'regime_period' => 0,
            'momentum_lookback' => 5,
            'entry_momentum' => 0.03,
            'exit_momentum' => 0.0,
            'risk_pct' => 0.5,
            'stop_loss_pct' => 0.12,
            'trailing_stop_pct' => 0.10,
            'max_hold_bars' => 18,
            'fee_bps' => 10.0,
            'slippage_bps' => 5.0,
        ];
    }

    /** @return list<Bar> */
    private function series(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 20) {
                $prices[] = 100.0;
            } elseif ($i < 45) {
                $prices[] = 100.0 + ($i - 19) * 1.8;
            } elseif ($i < 60) {
                $prices[] = 145.0 - ($i - 44) * 1.7;
            } else {
                $prices[] = 120.0 + sin($i / 4.0) * 0.5;
            }
        }

        $bars = [];
        $day = 86_400_000;
        $prev = $prices[0];
        foreach ($prices as $i => $price) {
            $open = $i === 0 ? $price : $prev;
            $bars[] = new Bar(
                $i * $day,
                $open,
                max($open, $price) * 1.004,
                min($open, $price) * 0.996,
                $price,
                1000.0,
                ($i + 1) * $day - 1,
            );
            $prev = $price;
        }

        return $bars;
    }
}
