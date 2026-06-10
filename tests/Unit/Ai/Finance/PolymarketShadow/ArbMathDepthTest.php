<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ArbMath;
use PHPUnit\Framework\TestCase;

final class ArbMathDepthTest extends TestCase
{
    public function test_long_depth_walks_levels_until_sum_crosses_one(): void
    {
        // Leg A: 100 @0.50 then 100 @0.55 ; Leg B: 60 @0.40 then 100 @0.48.
        // Segment 1 (60 sets): sum 0.90, profit 0.10/set => $6.00
        // Segment 2 (40 sets): A still 0.50, B now 0.48 => 0.98, profit 0.02/set => $0.80
        // Segment 3: A 0.55 + B 0.48 = 1.03 => stop. Total 100 sets, $6.80.
        $result = ArbMath::longBasketDepth([
            [['price' => 0.50, 'size' => 100.0], ['price' => 0.55, 'size' => 100.0]],
            [['price' => 0.40, 'size' => 60.0], ['price' => 0.48, 'size' => 100.0]],
        ]);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(100.0, $result['sets'], 1e-6);
        $this->assertEqualsWithDelta(6.80, $result['profit_usd'], 1e-6);
        $this->assertEqualsWithDelta(0.90, $result['marginal_sum_start'], 1e-9);
    }

    public function test_long_depth_finds_more_than_top_of_book(): void
    {
        $legs = [
            [['price' => 0.50, 'size' => 10.0], ['price' => 0.51, 'size' => 200.0]],
            [['price' => 0.45, 'size' => 300.0]],
        ];

        $top = ArbMath::longBasket([
            ['token' => 'a', 'ask' => 0.50, 'ask_size' => 10.0],
            ['token' => 'b', 'ask' => 0.45, 'ask_size' => 300.0],
        ]);
        $depth = ArbMath::longBasketDepth($legs);

        // Top-of-book sees 10 sets; depth walk captures 210 (10@0.95 + 200@0.96).
        $this->assertEqualsWithDelta(10.0, $top['sets'], 1e-9);
        $this->assertEqualsWithDelta(210.0, $depth['sets'], 1e-6);
        $this->assertEqualsWithDelta(10 * 0.05 + 200 * 0.04, $depth['profit_usd'], 1e-6);
    }

    public function test_long_depth_null_when_no_profitable_segment(): void
    {
        $this->assertNull(ArbMath::longBasketDepth([
            [['price' => 0.60, 'size' => 100.0]],
            [['price' => 0.45, 'size' => 100.0]],
        ]));
    }

    public function test_long_depth_respects_fee_and_min_profit(): void
    {
        $legs = [
            [['price' => 0.50, 'size' => 100.0]],
            [['price' => 0.45, 'size' => 100.0]],
        ];

        $this->assertNotNull(ArbMath::longBasketDepth($legs, 0.0, 0.005));
        $this->assertNull(ArbMath::longBasketDepth($legs, 0.05, 0.005));
    }

    public function test_short_depth_walks_bid_levels_and_freerolls_empty_legs(): void
    {
        // Sellable legs: A 50@0.60 then 50@0.55 ; B 80@0.46. Leg C has no bids (freeroll).
        // Segment 1 (50 sets): 1.06 => 0.06/set = $3.00
        // Segment 2 (30 sets): 0.55+0.46 = 1.01 => 0.01/set = $0.30. Total 80 sets, $3.30.
        $result = ArbMath::shortBasketDepth([
            [['price' => 0.60, 'size' => 50.0], ['price' => 0.55, 'size' => 50.0]],
            [['price' => 0.46, 'size' => 80.0]],
            [],
        ]);

        $this->assertNotNull($result);
        $this->assertSame(2, $result['sellable_legs']);
        $this->assertEqualsWithDelta(80.0, $result['sets'], 1e-6);
        $this->assertEqualsWithDelta(3.30, $result['profit_usd'], 1e-6);
        $this->assertEqualsWithDelta(1.06, $result['marginal_sum_start'], 1e-9);
    }

    public function test_short_depth_null_when_under_one_or_single_sellable(): void
    {
        $this->assertNull(ArbMath::shortBasketDepth([
            [['price' => 0.50, 'size' => 100.0]],
            [['price' => 0.45, 'size' => 100.0]],
        ]));
        $this->assertNull(ArbMath::shortBasketDepth([
            [['price' => 0.99, 'size' => 100.0]],
            [],
        ]));
    }
}
