<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAttemptLedger;
use PHPUnit\Framework\TestCase;

/**
 * Next-lever 5 — per-obra working memory. The ledger feeds failed approaches forward (do-not-repeat) and
 * detects thrashing (same failure recurring) so the loop converges instead of re-walking dead ends.
 */
final class AtlasLoopAttemptLedgerTest extends TestCase
{
    public function test_empty_ledger_has_no_guidance_and_no_thrash(): void
    {
        $l = new AtlasLoopAttemptLedger;
        $this->assertSame('', $l->guidance());
        $this->assertFalse($l->convergence()['thrashing']);
        $this->assertSame(0, $l->rounds());
    }

    public function test_guidance_lists_distinct_failed_approaches_most_recent_first(): void
    {
        $l = new AtlasLoopAttemptLedger;
        $l->record('surgical', 'hermes_cli', false, 'complexity_not_reduced');
        $l->record('extract_helper', 'minimax_m27', false, 'sibling_test_red');
        $g = $l->guidance();
        $this->assertStringContainsString('do NOT repeat', $g);
        $this->assertStringContainsString('extract_helper', $g);
        $this->assertStringContainsString('sibling_test_red', $g);
        $this->assertStringContainsString('complexity_not_reduced', $g);
        // most-recent failure appears before the earlier one
        $this->assertLessThan(strpos($g, 'complexity_not_reduced'), strpos($g, 'sibling_test_red'));
    }

    public function test_passing_attempts_are_not_in_guidance(): void
    {
        $l = new AtlasLoopAttemptLedger;
        $l->record('surgical', 'hermes_cli', true);
        $this->assertSame('', $l->guidance(), 'a winning approach is not a do-not-repeat');
    }

    public function test_thrashing_detected_when_same_failure_recurs(): void
    {
        $l = new AtlasLoopAttemptLedger;
        $l->record('a', 'hermes_cli', false, 'complexity_not_reduced');
        $l->record('b', 'minimax_m27', false, 'complexity_not_reduced');
        $l->record('c', 'codex', false, 'complexity_not_reduced');
        $c = $l->convergence(3);
        $this->assertTrue($c['thrashing'], 'same failure 3x => escalate, do not retry the same lane');
        $this->assertSame('complexity_not_reduced', $c['dominant_reason']);
        $this->assertSame(3, $c['dominant_count']);
    }

    public function test_distinct_failures_are_not_thrashing(): void
    {
        $l = new AtlasLoopAttemptLedger;
        $l->record('a', 'hermes_cli', false, 'complexity_not_reduced');
        $l->record('b', 'minimax_m27', false, 'sibling_test_red');
        $l->record('c', 'codex', false, 'scope_violation');
        $c = $l->convergence(3);
        $this->assertFalse($c['thrashing'], 'different failures each round = genuine exploration, not thrash');
        $this->assertSame(3, $c['distinct_failure_reasons']);
    }
}
