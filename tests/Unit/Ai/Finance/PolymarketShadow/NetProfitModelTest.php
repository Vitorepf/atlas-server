<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\NetProfitModel;
use PHPUnit\Framework\TestCase;

final class NetProfitModelTest extends TestCase
{
    public function test_long_and_short_have_different_fixed_cost(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20);

        $this->assertEqualsWithDelta(0.10, $model->fixedCostFor('long_sum_under'), 1e-9);
        $this->assertEqualsWithDelta(0.20, $model->fixedCostFor('short_sum_over'), 1e-9);
    }

    public function test_per_leg_cost_scales_with_legs(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20, perLegCost: 0.01);

        $this->assertEqualsWithDelta(0.10 + 0.05, $model->captureCost('long_sum_under', 5), 1e-9);
    }

    public function test_small_gross_that_gas_eats_is_not_worth_taking(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20);

        // $0.01 gross long, cost $0.10 => net -$0.09: a loss, skip.
        $e = $model->evaluate('long_sum_under', 0.01, 0.005, 3);
        $this->assertFalse($e['worth_taking']);
        $this->assertEqualsWithDelta(-0.09, $e['net_usd'], 1e-9);
    }

    public function test_small_gross_with_enough_depth_is_worth_taking(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20);

        // $0.65 gross long, cost $0.10 => net +$0.55: a small but real win, take it.
        $e = $model->evaluate('long_sum_under', 0.65, 0.01, 3);
        $this->assertTrue($e['worth_taking']);
        $this->assertEqualsWithDelta(0.55, $e['net_usd'], 1e-9);
    }

    public function test_short_costs_more_so_breakeven_is_higher(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20);

        // 0.01/set profit: long breaks even at 10 sets, short at 20 sets.
        $long = $model->evaluate('long_sum_under', 1.0, 0.01, 3);
        $short = $model->evaluate('short_sum_over', 1.0, 0.01, 3);

        $this->assertEqualsWithDelta(10.0, $long['breakeven_sets'], 1e-6);
        $this->assertEqualsWithDelta(20.0, $short['breakeven_sets'], 1e-6);
    }

    public function test_zero_profit_per_set_has_infinite_breakeven_sentinel(): void
    {
        $model = new NetProfitModel;
        $e = $model->evaluate('long_sum_under', 0.0, 0.0, 3);

        $this->assertSame(-1.0, $e['breakeven_sets']);
        $this->assertFalse($e['worth_taking']);
    }

    public function test_exactly_breakeven_is_not_worth_taking(): void
    {
        $model = new NetProfitModel(longFixedCost: 0.10, shortFixedCost: 0.20);

        // gross exactly equals cost => net 0 => not strictly positive.
        $e = $model->evaluate('long_sum_under', 0.10, 0.01, 3);
        $this->assertEqualsWithDelta(0.0, $e['net_usd'], 1e-9);
        $this->assertFalse($e['worth_taking']);
    }
}
