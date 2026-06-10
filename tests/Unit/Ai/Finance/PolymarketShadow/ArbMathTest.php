<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ArbMath;
use PHPUnit\Framework\TestCase;

final class ArbMathTest extends TestCase
{
    private function askLeg(float $ask, float $size = 100.0): array
    {
        return ['token' => 't'.spl_object_id(new \stdClass), 'ask' => $ask, 'ask_size' => $size];
    }

    private function bidLeg(float $bid, float $size = 100.0): array
    {
        return ['token' => 't'.spl_object_id(new \stdClass), 'bid' => $bid, 'bid_size' => $size];
    }

    public function test_long_basket_locks_profit_when_asks_sum_under_one(): void
    {
        // 3 legs summing 0.95 with min depth 50 sets => 0.05/set, $2.50 locked.
        $result = ArbMath::longBasket([
            $this->askLeg(0.50, 200.0),
            $this->askLeg(0.30, 50.0),
            $this->askLeg(0.15, 80.0),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('long_sum_under', $result['kind']);
        $this->assertEqualsWithDelta(0.95, $result['sum'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $result['profit_per_set'], 1e-9);
        $this->assertEqualsWithDelta(50.0, $result['sets'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $result['profit_usd'], 1e-9);
        $this->assertEqualsWithDelta(47.5, $result['cost_usd'], 1e-9);
        $this->assertSame('simple_buy_all_legs', $result['execution_class']);
    }

    public function test_long_basket_null_at_or_above_one(): void
    {
        $this->assertNull(ArbMath::longBasket([
            $this->askLeg(0.50), $this->askLeg(0.30), $this->askLeg(0.21),
        ]));
    }

    public function test_long_basket_respects_min_profit_threshold(): void
    {
        $legs = [$this->askLeg(0.50), $this->askLeg(0.30), $this->askLeg(0.197)];

        $this->assertNull(ArbMath::longBasket($legs, 0.0, 0.005));
        $this->assertNotNull(ArbMath::longBasket($legs, 0.0, 0.001));
    }

    public function test_long_basket_fee_eats_the_edge(): void
    {
        $legs = [$this->askLeg(0.50), $this->askLeg(0.30), $this->askLeg(0.15)];

        $this->assertNotNull(ArbMath::longBasket($legs, 0.0));
        $this->assertNull(ArbMath::longBasket($legs, 0.05));
    }

    public function test_long_basket_null_when_any_leg_unbuyable(): void
    {
        // ask = 1.0 means no real offer: basket cannot be completed.
        $this->assertNull(ArbMath::longBasket([
            $this->askLeg(0.40), $this->askLeg(0.30), $this->askLeg(1.0),
        ]));
        // zero depth on one leg
        $this->assertNull(ArbMath::longBasket([
            $this->askLeg(0.40), $this->askLeg(0.30), $this->askLeg(0.10, 0.0),
        ]));
        // single leg is never a basket
        $this->assertNull(ArbMath::longBasket([$this->askLeg(0.40)]));
    }

    public function test_short_basket_locks_profit_when_bids_sum_over_one(): void
    {
        // Mint set for $1, sell 3 legs for 1.06 with min sellable depth 40.
        $result = ArbMath::shortBasket([
            $this->bidLeg(0.55, 40.0),
            $this->bidLeg(0.31, 90.0),
            $this->bidLeg(0.20, 60.0),
        ]);

        $this->assertNotNull($result);
        $this->assertSame('short_sum_over', $result['kind']);
        $this->assertEqualsWithDelta(1.06, $result['sum'], 1e-9);
        $this->assertEqualsWithDelta(0.06, $result['profit_per_set'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $result['sets'], 1e-9);
        $this->assertEqualsWithDelta(2.4, $result['profit_usd'], 1e-9);
        $this->assertSame('requires_minting_full_set', $result['execution_class']);
    }

    public function test_short_basket_unsold_legs_are_freeroll_not_blockers(): void
    {
        // One leg has no bid: it is simply not sold; the other two still clear 1.0.
        $result = ArbMath::shortBasket([
            $this->bidLeg(0.60, 50.0),
            $this->bidLeg(0.45, 50.0),
            $this->bidLeg(0.0, 0.0),
        ]);

        $this->assertNotNull($result);
        $this->assertSame(3, $result['n_legs']);
        $this->assertSame(2, $result['sellable_legs']);
        $this->assertEqualsWithDelta(0.05, $result['profit_per_set'], 1e-9);
    }

    public function test_short_basket_null_when_bids_sum_under_one(): void
    {
        $this->assertNull(ArbMath::shortBasket([
            $this->bidLeg(0.50), $this->bidLeg(0.30), $this->bidLeg(0.15),
        ]));
    }

    public function test_short_basket_needs_at_least_two_sellable_legs(): void
    {
        $this->assertNull(ArbMath::shortBasket([
            $this->bidLeg(1.0 + 0.5, 10.0), // invalid bid >= 1 is ignored
            $this->bidLeg(0.0, 0.0),
            $this->bidLeg(0.99, 10.0),
        ]));
    }

    public function test_numeric_garbage_is_rejected_not_propagated(): void
    {
        $this->assertNull(ArbMath::longBasket([
            ['token' => 'a', 'ask' => NAN, 'ask_size' => 10.0],
            $this->askLeg(0.30),
        ]));
        $this->assertNull(ArbMath::shortBasket([
            ['token' => 'a', 'bid' => INF, 'bid_size' => 10.0],
            $this->bidLeg(0.60),
        ]));
    }
}
