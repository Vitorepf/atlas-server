<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate;
use PHPUnit\Framework\TestCase;

final class SecondEngineDivergenceGateTest extends TestCase
{
    public function test_fails_closed_when_independent_engine_is_unavailable(): void
    {
        $result = (new SecondEngineDivergenceGate)->evaluate(['trade_count' => 12, 'ann_sharpe' => 1.1, 'max_dd' => 0.12], null);

        $this->assertFalse($result['passed']);
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(['second_engine_unavailable'], $result['reasons']);
    }

    public function test_passes_when_second_engine_is_inside_tolerance(): void
    {
        $result = (new SecondEngineDivergenceGate)->evaluate(
            ['trade_count' => 20, 'ann_sharpe' => 1.0, 'max_dd' => 0.12, 'total_return' => 0.18, 'exposure' => 0.30, 'equity_curve_sample' => [1.0, 1.05, 1.18], 'holdout_passed' => true],
            ['trade_count' => 18, 'ann_sharpe' => 0.91, 'max_dd' => 0.14, 'total_return' => 0.16, 'exposure' => 0.34, 'equity_curve_sample' => [1.0, 1.04, 1.16], 'holdout_passed' => true],
        );

        $this->assertTrue($result['passed'], json_encode($result['reasons']));
    }

    public function test_rejects_large_engine_divergence(): void
    {
        $result = (new SecondEngineDivergenceGate)->evaluate(
            ['trade_count' => 20, 'ann_sharpe' => 0.7, 'max_dd' => 0.10],
            ['trade_count' => 10, 'ann_sharpe' => -0.2, 'max_dd' => 0.18],
        );

        $this->assertFalse($result['passed']);
        $this->assertContains('trade_count_diverged', $result['reasons']);
        $this->assertContains('sharpe_diverged', $result['reasons']);
        $this->assertContains('sharpe_sign_inverted', $result['reasons']);
        $this->assertContains('drawdown_diverged', $result['reasons']);
    }

    public function test_rejects_return_exposure_equity_curve_and_holdout_disagreement(): void
    {
        $result = (new SecondEngineDivergenceGate)->evaluate(
            ['trade_count' => 20, 'ann_sharpe' => 0.8, 'max_dd' => 0.10, 'total_return' => 0.18, 'exposure' => 0.20, 'equity_curve_sample' => [1.0, 1.05, 1.18], 'holdout_passed' => true],
            ['trade_count' => 20, 'ann_sharpe' => 0.78, 'max_dd' => 0.11, 'total_return' => -0.02, 'exposure' => 0.42, 'equity_curve_sample' => [1.0, 0.94, 0.98], 'holdout_passed' => false],
        );

        $this->assertFalse($result['passed']);
        $this->assertContains('return_diverged', $result['reasons']);
        $this->assertContains('return_sign_inverted', $result['reasons']);
        $this->assertContains('exposure_diverged', $result['reasons']);
        $this->assertContains('equity_curve_diverged', $result['reasons']);
        $this->assertContains('holdout_result_diverged', $result['reasons']);
    }
}
