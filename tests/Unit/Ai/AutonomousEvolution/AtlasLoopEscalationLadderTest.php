<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopEscalationLadder;
use PHPUnit\Framework\TestCase;

/**
 * Next-lever 2 — the escalation ladder. Runs as many rounds as needed: each failed round escalates to a
 * stronger tier, bounded by certification (the quality bar) and a round budget — not a fixed N.
 */
final class AtlasLoopEscalationLadderTest extends TestCase
{
    private AtlasLoopEscalationLadder $ladder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ladder = new AtlasLoopEscalationLadder;
    }

    public function test_certified_stops_with_accept(): void
    {
        $r = $this->ladder->next(['certified' => true, 'round' => 2, 'current_tier' => 'repair_from_refutation']);
        $this->assertTrue($r['stop']);
        $this->assertSame('accept', $r['action']);
        $this->assertSame('certified', $r['reason']);
    }

    public function test_first_round_starts_at_best_of_n(): void
    {
        $r = $this->ladder->next(['certified' => false, 'round' => 0, 'current_tier' => '', 'max_rounds' => 6]);
        $this->assertFalse($r['stop']);
        $this->assertSame('best_of_n', $r['next_tier']);
        $this->assertSame(1, $r['round']);
    }

    public function test_escalates_through_the_tiers_in_order(): void
    {
        $this->assertSame('repair_from_refutation', $this->ladder->next(['current_tier' => 'best_of_n', 'round' => 1, 'max_rounds' => 6])['next_tier']);
        $this->assertSame('decompose', $this->ladder->next(['current_tier' => 'repair_from_refutation', 'round' => 2, 'max_rounds' => 6])['next_tier']);
        $this->assertSame('escalate_provider', $this->ladder->next(['current_tier' => 'decompose', 'round' => 3, 'max_rounds' => 6])['next_tier']);
    }

    public function test_thrashing_is_reflected_in_the_reason(): void
    {
        $r = $this->ladder->next(['current_tier' => 'best_of_n', 'round' => 1, 'thrashing' => true, 'max_rounds' => 6]);
        $this->assertStringContainsString('thrashing->repair_from_refutation', $r['reason']);
    }

    public function test_budget_exhaustion_stops(): void
    {
        $r = $this->ladder->next(['certified' => false, 'round' => 6, 'max_rounds' => 6, 'current_tier' => 'decompose']);
        $this->assertTrue($r['stop']);
        $this->assertSame('give_up', $r['action']);
        $this->assertStringContainsString('budget_exhausted:6/6', $r['reason']);
    }

    public function test_ladder_exhausted_uncertified_stops(): void
    {
        $r = $this->ladder->next(['certified' => false, 'round' => 4, 'max_rounds' => 9, 'current_tier' => 'escalate_provider']);
        $this->assertTrue($r['stop']);
        $this->assertSame('give_up', $r['action']);
        $this->assertSame('ladder_exhausted_uncertified', $r['reason']);
    }
}
