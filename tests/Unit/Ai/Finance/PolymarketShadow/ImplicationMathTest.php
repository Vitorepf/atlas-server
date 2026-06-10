<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ImplicationMath;
use PHPUnit\Framework\TestCase;

final class ImplicationMathTest extends TestCase
{
    public function test_top_of_book_violation_locks_edge(): void
    {
        // A implies B but A's bid (0.62) crosses B's ask (0.55): edge 0.07/share.
        $result = ImplicationMath::violation(
            ['bid' => 0.62, 'bid_size' => 80.0],
            ['ask' => 0.55, 'ask_size' => 120.0],
        );

        $this->assertNotNull($result);
        $this->assertSame('implication_violation', $result['kind']);
        $this->assertEqualsWithDelta(0.07, $result['edge_per_share'], 1e-9);
        $this->assertEqualsWithDelta(80.0, $result['shares'], 1e-9);
        $this->assertEqualsWithDelta(5.6, $result['profit_usd'], 1e-9);
        // Cost of NO(A) + YES(B) = (1 - 0.62) + 0.55 = 0.93 per share.
        $this->assertEqualsWithDelta(74.4, $result['cost_usd'], 1e-9);
        $this->assertSame('buy_no_implicant_buy_yes_implied', $result['execution_class']);
    }

    public function test_no_violation_when_books_respect_implication(): void
    {
        // bid(A) <= ask(B): the implication band holds, nothing to detect.
        $this->assertNull(ImplicationMath::violation(
            ['bid' => 0.40, 'bid_size' => 50.0],
            ['ask' => 0.45, 'ask_size' => 50.0],
        ));
    }

    public function test_min_edge_floor_is_respected(): void
    {
        $implicant = ['bid' => 0.503, 'bid_size' => 50.0];
        $implied = ['ask' => 0.50, 'ask_size' => 50.0];

        $this->assertNull(ImplicationMath::violation($implicant, $implied, 0.0, 0.005));
        $this->assertNotNull(ImplicationMath::violation($implicant, $implied, 0.0, 0.001));
    }

    public function test_fee_eats_the_edge(): void
    {
        $implicant = ['bid' => 0.56, 'bid_size' => 50.0];
        $implied = ['ask' => 0.50, 'ask_size' => 50.0];

        $this->assertNotNull(ImplicationMath::violation($implicant, $implied, 0.0));
        $this->assertNull(ImplicationMath::violation($implicant, $implied, 0.06));
    }

    public function test_numeric_garbage_is_rejected_not_propagated(): void
    {
        $this->assertNull(ImplicationMath::violation(
            ['bid' => NAN, 'bid_size' => 50.0],
            ['ask' => 0.50, 'ask_size' => 50.0],
        ));
        $this->assertNull(ImplicationMath::violation(
            ['bid' => 0.60, 'bid_size' => 50.0],
            ['ask' => INF, 'ask_size' => 50.0],
        ));
        // Boundary prices mean "no real order": never a violation.
        $this->assertNull(ImplicationMath::violation(
            ['bid' => 1.0, 'bid_size' => 50.0],
            ['ask' => 0.50, 'ask_size' => 50.0],
        ));
        $this->assertNull(ImplicationMath::violation(
            ['bid' => 0.60, 'bid_size' => 0.0],
            ['ask' => 0.50, 'ask_size' => 50.0],
        ));
    }

    public function test_depth_walk_pairs_levels_until_edge_floor(): void
    {
        // Bids (descending) on A: 0.62x30, 0.58x40. Asks (ascending) on B: 0.55x50, 0.57x100.
        // Pairing: 30 @ (0.62-0.55)=0.07, 20 @ (0.58-0.55)=0.03, 20 @ (0.58-0.57)=0.01.
        $result = ImplicationMath::violationDepth(
            [['price' => 0.62, 'size' => 30.0], ['price' => 0.58, 'size' => 40.0]],
            [['price' => 0.55, 'size' => 50.0], ['price' => 0.57, 'size' => 100.0]],
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(70.0, $result['shares'], 1e-9);
        $this->assertEqualsWithDelta(0.07, $result['edge_start'], 1e-9);
        $this->assertEqualsWithDelta(30 * 0.07 + 20 * 0.03 + 20 * 0.01, $result['profit_usd'], 1e-6);
        // Cost: 30*((1-0.62)+0.55) + 20*((1-0.58)+0.55) + 20*((1-0.58)+0.57).
        $this->assertEqualsWithDelta(30 * 0.93 + 20 * 0.97 + 20 * 0.99, $result['cost_usd'], 1e-6);
        $this->assertSame('buy_no_implicant_buy_yes_implied', $result['execution_class']);
    }

    public function test_depth_walk_stops_at_min_edge_not_at_zero(): void
    {
        // Second pairing has edge 0.01 < floor 0.02: only the first 30 execute.
        $result = ImplicationMath::violationDepth(
            [['price' => 0.62, 'size' => 30.0], ['price' => 0.58, 'size' => 40.0]],
            [['price' => 0.55, 'size' => 30.0], ['price' => 0.57, 'size' => 100.0]],
            0.0,
            0.02,
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(30.0, $result['shares'], 1e-9);
        $this->assertEqualsWithDelta(30 * 0.07, $result['profit_usd'], 1e-6);
    }

    public function test_depth_walk_null_when_top_of_book_already_clean(): void
    {
        $this->assertNull(ImplicationMath::violationDepth(
            [['price' => 0.50, 'size' => 100.0]],
            [['price' => 0.52, 'size' => 100.0]],
        ));
    }

    public function test_depth_walk_null_on_empty_or_garbage_books(): void
    {
        $this->assertNull(ImplicationMath::violationDepth([], [['price' => 0.5, 'size' => 10.0]]));
        $this->assertNull(ImplicationMath::violationDepth([['price' => 0.6, 'size' => 10.0]], []));
        $this->assertNull(ImplicationMath::violationDepth(
            [['price' => NAN, 'size' => 10.0]],
            [['price' => 0.5, 'size' => 10.0]],
        ));
        $this->assertNull(ImplicationMath::violationDepth(
            [['price' => 0.6, 'size' => 0.0]],
            [['price' => 0.5, 'size' => 10.0]],
        ));
    }

    public function test_depth_walk_fee_shifts_the_stop_point(): void
    {
        $bids = [['price' => 0.62, 'size' => 30.0], ['price' => 0.58, 'size' => 40.0]];
        $asks = [['price' => 0.55, 'size' => 200.0]];

        $noFee = ImplicationMath::violationDepth($bids, $asks, 0.0, 0.005);
        $this->assertNotNull($noFee);
        $this->assertEqualsWithDelta(70.0, $noFee['shares'], 1e-9);

        // Fee 0.025 kills the second level (0.58 - 0.55 - 0.025 = 0.005 < floor? no, == floor).
        $fee = ImplicationMath::violationDepth($bids, $asks, 0.026, 0.005);
        $this->assertNotNull($fee);
        $this->assertEqualsWithDelta(30.0, $fee['shares'], 1e-9);
    }
}
