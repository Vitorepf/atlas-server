<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyRobustnessChecks;
use PHPUnit\Framework\TestCase;

final class StrategyRobustnessChecksTest extends TestCase
{
    public function test_cost_stress_requires_strategy_to_survive_double_costs(): void
    {
        $checks = new StrategyRobustnessChecks;

        $pass = $checks->costStress(
            ['ann_sharpe' => 1.2, 'max_dd' => 0.20],
            ['ann_sharpe' => 0.25, 'max_dd' => 0.25],
            0.0,
            0.6,
        );
        $fail = $checks->costStress(
            ['ann_sharpe' => 1.2, 'max_dd' => 0.20],
            ['ann_sharpe' => -0.05, 'max_dd' => 0.25],
            0.0,
            0.6,
        );

        $this->assertTrue($pass['passed']);
        $this->assertFalse($fail['passed']);
    }

    public function test_neighborhood_requires_enough_reasonable_parameter_neighbors(): void
    {
        $checks = new StrategyRobustnessChecks;

        $pass = $checks->neighborhood([
            ['ann_sharpe' => 0.1],
            ['ann_sharpe' => 0.2],
            ['ann_sharpe' => 0.3],
            ['ann_sharpe' => -0.1],
        ], 3, 0.0);
        $fail = $checks->neighborhood([
            ['ann_sharpe' => -0.3],
            ['ann_sharpe' => -0.2],
            null,
        ], 3, 0.0);

        $this->assertTrue($pass['passed']);
        $this->assertFalse($fail['passed']);
    }
}
