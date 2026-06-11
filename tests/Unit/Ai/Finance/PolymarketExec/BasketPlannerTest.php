<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\BasketPlanner;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use PHPUnit\Framework\TestCase;

final class BasketPlannerTest extends TestCase
{
    private function cfg(array $o = []): PolyExecConfig
    {
        return new PolyExecConfig(
            liveEnabled: $o['liveEnabled'] ?? false,
            maxBasketUsd: $o['maxBasketUsd'] ?? 8.0,
            dailyCapUsd: $o['dailyCapUsd'] ?? 25.0,
            maxConcurrentBaskets: $o['maxConcurrentBaskets'] ?? 2,
            minDepthMultiple: $o['minDepthMultiple'] ?? 3.0,
            minPersistenceSeconds: $o['minPersistenceSeconds'] ?? 600,
            minNetEdgePerSet: $o['minNetEdgePerSet'] ?? 0.01,
            maxResolutionHours: $o['maxResolutionHours'] ?? 72.0,
            slippageBps: $o['slippageBps'] ?? 100,
            takerFeeRate: $o['takerFeeRate'] ?? 0.0,
            estGasUsdPerBasket: $o['estGasUsdPerBasket'] ?? 0.0,
            killSwitchPath: $o['killSwitchPath'] ?? '/tmp/none-'.uniqid(),
        );
    }

    /**
     * @param  array<string, array{best: float, depth: float}>  $spec
     */
    private function books(array $spec): callable
    {
        return function (string $token) use ($spec): ?array {
            if (! isset($spec[$token])) {
                return null;
            }
            $best = $spec[$token]['best'];
            $depth = $spec[$token]['depth'];

            // Single ask level at `best` with `depth` size (>= limit covers it).
            return [
                'asks' => [['price' => $best, 'size' => $depth]],
                'bids' => [['price' => max(0.01, $best - 0.05), 'size' => $depth]],
            ];
        };
    }

    private function meta(?string $endDate): callable
    {
        return fn (string $slug): ?array => $endDate === null ? null : ['endDate' => $endDate];
    }

    public function test_legs_are_ordered_thinnest_depth_first(): void
    {
        $planner = new BasketPlanner(
            $this->cfg(),
            $this->books([
                'A' => ['best' => 0.30, 'depth' => 50.0],
                'B' => ['best' => 0.30, 'depth' => 10.0],  // thinnest
                'C' => ['best' => 0.30, 'depth' => 90.0],
            ]),
            $this->meta('2999-01-01T00:00:00Z'),
        );

        $plan = $planner->plan('evt', 'long_sum_under', [
            ['token' => 'A'], ['token' => 'B'], ['token' => 'C'],
        ], persistenceSeconds: 1200);

        $this->assertNotNull($plan);
        $this->assertSame('B', $plan->legs[0]['token'], 'thinnest leg must be filled first');
        $this->assertSame('A', $plan->legs[1]['token']);
        $this->assertSame('C', $plan->legs[2]['token']);
        $this->assertSame([0, 1, 2], array_column($plan->legs, 'position'));
    }

    public function test_limit_price_applies_slippage_and_net_edge_is_one_minus_sum(): void
    {
        $planner = new BasketPlanner(
            $this->cfg(['slippageBps' => 100]),
            $this->books([
                'A' => ['best' => 0.30, 'depth' => 100.0],
                'B' => ['best' => 0.30, 'depth' => 100.0],
                'C' => ['best' => 0.30, 'depth' => 100.0],
            ]),
            $this->meta('2999-01-01T00:00:00Z'),
        );

        $plan = $planner->plan('evt', 'long_sum_under', [['token' => 'A'], ['token' => 'B'], ['token' => 'C']], 1200);

        $this->assertNotNull($plan);
        // 0.30 * 1.01 = 0.303
        $this->assertEqualsWithDelta(0.303, $plan->legs[0]['limit_price'], 1e-6);
        $this->assertEqualsWithDelta(0.90, $plan->targetSum, 1e-6);
        $this->assertEqualsWithDelta(0.10, $plan->estEdgePerSet, 1e-6); // 1 - 0.90
    }

    public function test_sizing_is_bounded_by_the_per_basket_cap(): void
    {
        // Deep books, small cap -> the cap binds, not depth.
        $planner = new BasketPlanner(
            $this->cfg(['maxBasketUsd' => 1.80]),
            $this->books([
                'A' => ['best' => 0.30, 'depth' => 1000.0],
                'B' => ['best' => 0.30, 'depth' => 1000.0],
                'C' => ['best' => 0.30, 'depth' => 1000.0],
            ]),
            $this->meta('2999-01-01T00:00:00Z'),
        );

        $plan = $planner->plan('evt', 'long_sum_under', [['token' => 'A'], ['token' => 'B'], ['token' => 'C']], 1200);

        $this->assertNotNull($plan);
        // cap 1.80 / 0.90 per set = 2.0 sets max.
        $this->assertEqualsWithDelta(2.0, $plan->targetSets, 1e-4);
        $this->assertLessThanOrEqual(1.80 + 1e-6, $plan->targetCostUsd);
    }

    public function test_sizing_is_bounded_by_depth_buffer_when_thin(): void
    {
        // Thin thinnest leg (4 shares) caps sets below the cap-implied size and
        // leaves the 3x depth buffer the execution gate requires.
        $planner = new BasketPlanner(
            $this->cfg(['maxBasketUsd' => 100.0]),
            $this->books([
                'A' => ['best' => 0.30, 'depth' => 4.0],
                'B' => ['best' => 0.30, 'depth' => 500.0],
                'C' => ['best' => 0.30, 'depth' => 500.0],
            ]),
            $this->meta('2999-01-01T00:00:00Z'),
        );

        $plan = $planner->plan('evt', 'long_sum_under', [['token' => 'A'], ['token' => 'B'], ['token' => 'C']], 1200);

        $this->assertNotNull($plan);
        $this->assertEqualsWithDelta(1.3333, $plan->targetSets, 1e-4);
        $this->assertEqualsWithDelta(4.0, $plan->executableDepthShares, 1e-4);
        $this->assertGreaterThanOrEqual($plan->targetSets * 3.0, $plan->executableDepthShares);
    }

    public function test_rejects_non_long_kind_and_sum_at_or_above_one(): void
    {
        $planner = new BasketPlanner(
            $this->cfg(),
            $this->books([
                'A' => ['best' => 0.40, 'depth' => 100.0],
                'B' => ['best' => 0.40, 'depth' => 100.0],
                'C' => ['best' => 0.40, 'depth' => 100.0], // sum 1.20 >= 1
            ]),
            $this->meta('2999-01-01T00:00:00Z'),
        );

        $this->assertNull($planner->plan('evt', 'short_sum_over', [['token' => 'A'], ['token' => 'B']], 1200), 'v1 is long-only');
        $this->assertNull($planner->plan('evt', 'long_sum_under', [['token' => 'A'], ['token' => 'B'], ['token' => 'C']], 1200), 'no edge when sum >= 1');
    }

    public function test_resolution_hours_null_when_end_date_unknown(): void
    {
        $planner = new BasketPlanner(
            $this->cfg(),
            $this->books([
                'A' => ['best' => 0.30, 'depth' => 100.0],
                'B' => ['best' => 0.30, 'depth' => 100.0],
                'C' => ['best' => 0.30, 'depth' => 100.0],
            ]),
            $this->meta(null),
        );

        $plan = $planner->plan('evt', 'long_sum_under', [['token' => 'A'], ['token' => 'B'], ['token' => 'C']], 1200);
        $this->assertNotNull($plan);
        $this->assertNull($plan->resolutionHours, 'unknown resolution -> null so the gate fails closed');
    }
}
