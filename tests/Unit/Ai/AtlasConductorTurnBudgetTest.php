<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecide\AtlasConductorTurnBudget;
use PHPUnit\Framework\TestCase;

class AtlasConductorTurnBudgetTest extends TestCase
{
    public function test_disabled_budget_never_refuses(): void
    {
        $b = new AtlasConductorTurnBudget(0.0);

        $this->assertFalse($b->enabled());
        $this->assertTrue($b->tryConsume(1_000_000));
        $this->assertSame(INF, $b->remaining());
        $this->assertFalse($b->exhausted());
    }

    public function test_consumes_within_ceiling_and_tracks_remaining(): void
    {
        $b = new AtlasConductorTurnBudget(100.0);

        $this->assertTrue($b->tryConsume(40));
        $this->assertSame(40.0, $b->spent());
        $this->assertSame(60.0, $b->remaining());
        $this->assertTrue($b->tryConsume(60));
        $this->assertTrue($b->exhausted());
    }

    public function test_refuses_overspend_without_consuming(): void
    {
        $b = new AtlasConductorTurnBudget(100.0);
        $this->assertTrue($b->tryConsume(80));

        // A request that would exceed the ceiling is refused, and spend is unchanged.
        $this->assertFalse($b->tryConsume(40));
        $this->assertSame(80.0, $b->spent());
        $this->assertSame(20.0, $b->remaining());

        // A request that fits still succeeds.
        $this->assertTrue($b->tryConsume(20));
        $this->assertTrue($b->exhausted());
        $this->assertSame(0.0, $b->remaining());
    }

    public function test_never_throws_on_exhaustion(): void
    {
        $b = new AtlasConductorTurnBudget(10.0);
        $b->tryConsume(10);

        // The whole point: refusal is a return value, not an exception.
        $this->assertFalse($b->tryConsume(1));
        $this->assertTrue($b->exhausted());
    }
}
