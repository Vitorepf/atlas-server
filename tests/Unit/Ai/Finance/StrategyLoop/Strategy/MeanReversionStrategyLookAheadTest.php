<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use PHPUnit\Framework\TestCase;

final class MeanReversionStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new MeanReversionStrategy)->run($this->series(70), $this->params());
        $prefix = (new MeanReversionStrategy)->run($this->series(45), $this->params());

        $this->assertCount(45, $prefix->equityCurve);
        $this->assertGreaterThanOrEqual(45, count($full->equityCurve));

        for ($i = 0; $i < 45; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} - look-ahead leak");
        }
        for ($i = 0; $i < 44; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} - look-ahead leak");
        }
    }

    public function test_clear_dip_and_rebound_produces_a_long_trade(): void
    {
        $result = (new MeanReversionStrategy)->run($this->series(70), $this->params());

        $this->assertGreaterThanOrEqual(1, $result->nTrades);
        $this->assertGreaterThanOrEqual(20, (int) $result->trades[0]['entry_idx']);
        $this->assertGreaterThan((int) $result->trades[0]['entry_idx'], (int) $result->trades[0]['exit_idx']);
    }

    /** @return array<string,float|int> */
    private function params(): array
    {
        return [
            'regime_period' => 0,
            'lookback' => 8,
            'entry_z' => 1.0,
            'exit_z' => 0.0,
            'risk_pct' => 0.5,
            'stop_loss_pct' => 0.25,
            'max_hold_bars' => 12,
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
            } elseif ($i < 25) {
                $prices[] = 100.0 - ($i - 19) * 2.5;
            } elseif ($i < 38) {
                $prices[] = 87.5 + ($i - 24) * 1.35;
            } else {
                $prices[] = 105.0 + sin($i / 3.0) * 0.4;
            }
        }

        $bars = [];
        $day = 86_400_000;
        $prev = $prices[0];
        foreach ($prices as $i => $price) {
            $open = $i === 0 ? $price : $prev;
            $high = max($open, $price) * 1.004;
            $low = min($open, $price) * 0.996;
            $bars[] = new Bar($i * $day, $open, $high, $low, $price, 1000.0, ($i + 1) * $day - 1);
            $prev = $price;
        }

        return $bars;
    }
}
