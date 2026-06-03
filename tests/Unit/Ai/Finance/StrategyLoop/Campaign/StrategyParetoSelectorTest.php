<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyParetoSelector;
use PHPUnit\Framework\TestCase;

final class StrategyParetoSelectorTest extends TestCase
{
    public function test_front_keeps_tradeoffs_instead_of_only_highest_sharpe(): void
    {
        $selector = new StrategyParetoSelector;
        $front = $selector->front([
            ['name' => 'fragile-high-sharpe', 'ann_sharpe' => 2.0, 'max_dd' => 0.5, 'stability_score' => 0.1, 'robustness_score' => 0.1],
            ['name' => 'robust-lower-sharpe', 'ann_sharpe' => 1.2, 'max_dd' => 0.1, 'stability_score' => 0.9, 'robustness_score' => 0.8],
            ['name' => 'dominated', 'ann_sharpe' => 1.0, 'max_dd' => 0.3, 'stability_score' => 0.2, 'robustness_score' => 0.1],
        ]);

        $names = array_column($front, 'name');
        $this->assertContains('fragile-high-sharpe', $names);
        $this->assertContains('robust-lower-sharpe', $names);
        $this->assertNotContains('dominated', $names);
    }

    public function test_select_elite_is_finite_and_prioritizes_pareto_front(): void
    {
        $elite = (new StrategyParetoSelector)->selectElite([
            ['name' => 'a', 'ann_sharpe' => 1.0, 'max_dd' => 0.2, 'stability_score' => 0.5, 'robustness_score' => 0.4],
            ['name' => 'b', 'ann_sharpe' => 0.8, 'max_dd' => 0.1, 'stability_score' => 0.9, 'robustness_score' => 0.8],
            ['name' => 'c', 'ann_sharpe' => 0.1, 'max_dd' => 0.9, 'stability_score' => 0.0, 'robustness_score' => -0.5],
        ], 2);

        $this->assertCount(2, $elite);
        $this->assertNotContains('c', array_column($elite, 'name'));
    }
}
