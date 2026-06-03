<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Campaign\ExternalPythonTrendBreakoutReplay;
use App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use PHPUnit\Framework\TestCase;

final class ExternalPythonTrendBreakoutReplayTest extends TestCase
{
    public function test_external_python_replay_matches_primary_engine_inside_gate_tolerance(): void
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
        $secondary = (new ExternalPythonTrendBreakoutReplay)->evaluate($bars, $params, 365.0);

        $this->assertSame('ready', $secondary['status'], json_encode($secondary));
        $this->assertArrayHasKey('total_return', $secondary);
        $this->assertArrayHasKey('exposure', $secondary);
        $this->assertNotEmpty($secondary['equity_curve_sample']);
        $this->assertTrue($secondary['holdout_passed']);
        $this->assertTrue((new SecondEngineDivergenceGate)->evaluate($primaryReport, $secondary)['passed']);
    }

    public function test_external_python_replay_matches_mean_reversion_family_inside_gate_tolerance(): void
    {
        $bars = $this->meanReversionBars();
        $params = [
            'regime_period' => 0,
            'lookback' => 8,
            'entry_z' => 1.0,
            'exit_z' => 0.0,
            'risk_pct' => 0.5,
            'stop_loss_pct' => 0.25,
            'max_hold_bars' => 12,
            'fee_bps' => 10,
            'slippage_bps' => 5,
        ];
        $primary = (new MeanReversionStrategy)->run($bars, $params);
        $metrics = new HonestMetrics;
        $primaryReport = [
            'trade_count' => $primary->nTrades,
            'ann_sharpe' => $metrics->sharpe($primary->dailyReturns, 365.0),
            'max_dd' => $metrics->maxDrawdown($primary->equityCurve),
        ];
        $secondary = (new ExternalPythonTrendBreakoutReplay)->evaluate($bars, $params, 365.0, 'mean-reversion-v1');

        $this->assertSame('ready', $secondary['status'], json_encode($secondary));
        $this->assertTrue((new SecondEngineDivergenceGate)->evaluate($primaryReport, $secondary)['passed']);
    }

    public function test_external_python_replay_matches_momentum_family_inside_gate_tolerance(): void
    {
        $bars = $this->momentumBars();
        $params = [
            'regime_period' => 0,
            'momentum_lookback' => 5,
            'entry_momentum' => 0.03,
            'exit_momentum' => 0.0,
            'risk_pct' => 0.5,
            'stop_loss_pct' => 0.12,
            'trailing_stop_pct' => 0.10,
            'max_hold_bars' => 18,
            'fee_bps' => 10,
            'slippage_bps' => 5,
        ];
        $primary = (new MomentumStrategy)->run($bars, $params);
        $metrics = new HonestMetrics;
        $primaryReport = [
            'trade_count' => $primary->nTrades,
            'ann_sharpe' => $metrics->sharpe($primary->dailyReturns, 365.0),
            'max_dd' => $metrics->maxDrawdown($primary->equityCurve),
        ];
        $secondary = (new ExternalPythonTrendBreakoutReplay)->evaluate($bars, $params, 365.0, 'momentum-v1');

        $this->assertSame('ready', $secondary['status'], json_encode($secondary));
        $this->assertTrue((new SecondEngineDivergenceGate)->evaluate($primaryReport, $secondary)['passed']);
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

    /** @return list<Bar> */
    private function meanReversionBars(): array
    {
        $prices = [];
        for ($i = 0; $i < 90; $i++) {
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

    /** @return list<Bar> */
    private function momentumBars(): array
    {
        $prices = [];
        for ($i = 0; $i < 90; $i++) {
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
