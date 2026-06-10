<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\ShortBasketPlanner;
use Tests\TestCase;

final class ShortBasketPlannerTest extends TestCase
{
    private function cfg(array $o = []): PolyExecConfig
    {
        return new PolyExecConfig(
            liveEnabled: false, maxBasketUsd: $o['maxBasketUsd'] ?? 8.0, dailyCapUsd: 25.0,
            maxConcurrentBaskets: 2, minDepthMultiple: 3.0, minPersistenceSeconds: 600,
            minNetEdgePerSet: 0.01, maxResolutionHours: 72.0, slippageBps: 100, takerFeeRate: 0.0,
            estGasUsdPerBasket: 0.0, killSwitchPath: sys_get_temp_dir().'/k'.uniqid(),
            estMintGasUsd: $o['estMintGasUsd'] ?? 0.05, shortEnabled: $o['shortEnabled'] ?? true,
        );
    }

    /** @param array<string, array{bids: list<array{price: float, size: float}>}> $books */
    private function planner(array $books, array $cfgOpts = []): ShortBasketPlanner
    {
        $bookSource = fn (string $token): ?array => isset($books[$token])
            ? ['asks' => [['price' => 0.9, 'size' => 10]], 'bids' => $books[$token]['bids']]
            : null;
        $meta = fn (string $slug): array => ['endDate' => '2999-01-01T00:00:00Z', 'negRisk' => true, 'negRiskMarketID' => 'cond-1'];

        return new ShortBasketPlanner($this->cfg($cfgOpts), $bookSource, $meta);
    }

    public function test_builds_plan_partitioning_sellable_and_freeroll(): void
    {
        // 3 sellable legs (bids sum 1.20) + 1 outcome with no bid (freeroll).
        $books = [
            'A' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'B' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'C' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'D' => ['bids' => []], // no bid -> freeroll
        ];
        $legs = [['token' => 'A'], ['token' => 'B'], ['token' => 'C'], ['token' => 'D']];

        $plan = $this->planner($books)->plan('evt', $legs, 1200, 8.0);

        $this->assertNotNull($plan);
        $this->assertSame('short_sum_over', $plan->kind);
        $this->assertSame('requires_minting_full_set', $plan->executionClass);
        $this->assertSame(3, $plan->nLegs(), 'three sellable legs');
        $this->assertSame(['A', 'B', 'C', 'D'], $plan->allTokenIds, 'the full set is minted');
        $this->assertSame(['D'], $plan->freerollTokens);
        $this->assertEqualsWithDelta(1.20, $plan->targetSumBids, 1e-6);
        $this->assertSame('cond-1', $plan->conditionId);
        $this->assertTrue($plan->negRisk);
        // edge/set = 1.20 - 1 - gas/sets; with 8 sets the gas/set is tiny -> ~0.19+.
        $this->assertGreaterThan(0.18, $plan->estEdgePerSet);
    }

    public function test_sizes_the_mint_by_the_budget_not_the_full_depth(): void
    {
        $books = [
            'A' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'B' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'C' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
        ];
        $legs = [['token' => 'A'], ['token' => 'B'], ['token' => 'C']];

        // $5 budget, $1/set -> 5 sets (not the 1000 depth).
        $plan = $this->planner($books)->plan('evt', $legs, 1200, 5.0);

        $this->assertNotNull($plan);
        $this->assertEqualsWithDelta(5.0, $plan->targetSets, 1e-6);
        $this->assertEqualsWithDelta(5.0, $plan->mintCostUsd, 1e-6, 'capital deployed = $1/set mint');
        $this->assertEqualsWithDelta(1000.0, $plan->executableDepthShares, 1e-6);
    }

    public function test_rejects_when_bids_do_not_sum_over_one(): void
    {
        $books = [
            'A' => ['bids' => [['price' => 0.30, 'size' => 1000]]],
            'B' => ['bids' => [['price' => 0.30, 'size' => 1000]]],
            'C' => ['bids' => [['price' => 0.30, 'size' => 1000]]], // sum 0.90 < 1
        ];
        $legs = [['token' => 'A'], ['token' => 'B'], ['token' => 'C']];

        $this->assertNull($this->planner($books)->plan('evt', $legs, 1200, 8.0));
    }

    public function test_rejects_when_fewer_than_two_legs_are_sellable(): void
    {
        $books = [
            'A' => ['bids' => [['price' => 0.95, 'size' => 1000]]],
            'B' => ['bids' => []],
            'C' => ['bids' => []],
        ];
        $legs = [['token' => 'A'], ['token' => 'B'], ['token' => 'C']];

        $this->assertNull($this->planner($books)->plan('evt', $legs, 1200, 8.0));
    }

    public function test_returns_null_when_short_disabled(): void
    {
        $books = [
            'A' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'B' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
            'C' => ['bids' => [['price' => 0.40, 'size' => 1000]]],
        ];
        $legs = [['token' => 'A'], ['token' => 'B'], ['token' => 'C']];

        $this->assertNull($this->planner($books, ['shortEnabled' => false])->plan('evt', $legs, 1200, 8.0));
    }
}
