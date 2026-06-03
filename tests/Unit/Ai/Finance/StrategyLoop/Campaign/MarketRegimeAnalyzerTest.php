<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Campaign\MarketRegimeAnalyzer;
use PHPUnit\Framework\TestCase;

final class MarketRegimeAnalyzerTest extends TestCase
{
    public function test_summarizes_strategy_returns_by_market_regime_and_volatility(): void
    {
        $bars = [];
        $price = 100.0;
        for ($i = 0; $i < 80; $i++) {
            $price *= $i < 30 ? 1.02 : ($i < 55 ? 0.98 : 1.001);
            $bars[] = new Bar(1_600_000_000_000 + $i * 86_400_000, $price, $price * 1.01, $price * 0.99, $price, 100.0, 1_600_000_000_000 + ($i + 1) * 86_400_000 - 1);
        }
        $strategyReturns = array_fill(0, 79, 0.001);

        $summary = (new MarketRegimeAnalyzer)->summarize($bars, $strategyReturns, 365.0, 20, 10);

        $this->assertGreaterThan(0, $summary['bull']['observations']);
        $this->assertGreaterThan(0, $summary['bear']['observations']);
        $this->assertGreaterThan(0, $summary['lateral']['observations']);
        $this->assertSame(79, $summary['high_volatility']['observations'] + $summary['low_volatility']['observations']);
    }
}
