<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Campaign\IndependentTrendBreakoutReplay;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use PHPUnit\Framework\TestCase;

final class IndependentTrendBreakoutReplayTest extends TestCase
{
    public function test_independent_replay_matches_primary_engine_within_divergence_tolerance(): void
    {
        $bars = $this->bars();
        $params = [
            'regime_period' => 10,
            'entry_lookback' => 5,
            'exit_lookback' => 5,
            'atr_period' => 5,
            'atr_mult' => 2.0,
            'risk_pct' => 0.2,
            'fee_bps' => 10,
            'slippage_bps' => 5,
            'min_hold_bars' => 1,
        ];
        $primary = (new TrendBreakoutStrategy)->run($bars, $params);
        $metrics = new HonestMetrics;
        $primaryReport = [
            'trade_count' => $primary->nTrades,
            'ann_sharpe' => $metrics->sharpe($primary->dailyReturns, 365.0),
            'max_dd' => $metrics->maxDrawdown($primary->equityCurve),
        ];
        $secondary = (new IndependentTrendBreakoutReplay)->evaluate($bars, $params, 365.0);

        $this->assertTrue(
            (new \App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate)->evaluate($primaryReport, $secondary)['passed']
        );
    }

    /** @return list<Bar> */
    private function bars(): array
    {
        $bars = [];
        $price = 100.0;
        for ($i = 0; $i < 140; $i++) {
            $price *= $i < 60 ? 1.01 : ($i < 95 ? 0.995 : 1.012);
            $bars[] = new Bar(
                1_600_000_000_000 + $i * 86_400_000,
                $price * 0.995,
                $price * 1.015,
                $price * 0.985,
                $price,
                100.0,
                1_600_000_000_000 + ($i + 1) * 86_400_000 - 1,
            );
        }

        return $bars;
    }
}
