<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCompletenessGate;
use PHPUnit\Framework\TestCase;

/**
 * Next-lever 1 — completeness certification. A delivery is complete only when every REQUIRED criterion is
 * satisfied and coverage clears the floor. Fail-open on an empty checklist.
 */
final class AtlasLoopCompletenessGateTest extends TestCase
{
    public function test_empty_checklist_is_fail_open(): void
    {
        $r = (new AtlasLoopCompletenessGate)->evaluate([]);
        $this->assertTrue($r['complete'], 'no declared criteria => not gated here (byte-identical)');
        $this->assertNull($r['reason']);
    }

    public function test_all_criteria_satisfied_is_complete(): void
    {
        $r = (new AtlasLoopCompletenessGate)->evaluate([
            ['id' => 'worst_method_cx_below_8', 'satisfied' => true],
            ['id' => 'no_new_branches', 'satisfied' => true],
            ['id' => 'class_total_cx_below_30', 'satisfied' => true],
        ]);
        $this->assertTrue($r['complete']);
        $this->assertSame(1.0, $r['coverage']);
        $this->assertNull($r['reason']);
    }

    public function test_a_required_unmet_criterion_blocks_completeness(): void
    {
        $r = (new AtlasLoopCompletenessGate)->evaluate([
            ['id' => 'worst_method_simplified', 'satisfied' => true],
            ['id' => 'class_no_longer_god', 'satisfied' => false, 'required' => true],
        ]);
        $this->assertFalse($r['complete'], 'simplified one method but the class is still god — not complete');
        $this->assertContains('class_no_longer_god', $r['required_missing']);
        $this->assertStringContainsString('required_unmet:class_no_longer_god', (string) $r['reason']);
    }

    public function test_optional_miss_passes_when_coverage_floor_met(): void
    {
        $r = (new AtlasLoopCompletenessGate)->evaluate([
            ['id' => 'core_behaviour', 'satisfied' => true, 'required' => true],
            ['id' => 'nice_to_have_doc', 'satisfied' => false, 'required' => false],
        ], 0.5);
        $this->assertTrue($r['complete'], 'all required met + coverage 0.5 >= floor 0.5');
        $this->assertSame(0.5, $r['coverage']);
    }

    public function test_coverage_below_floor_blocks_even_all_optional(): void
    {
        $r = (new AtlasLoopCompletenessGate)->evaluate([
            ['id' => 'a', 'satisfied' => true, 'required' => false],
            ['id' => 'b', 'satisfied' => false, 'required' => false],
            ['id' => 'c', 'satisfied' => false, 'required' => false],
        ], 0.8);
        $this->assertFalse($r['complete']);
        $this->assertStringContainsString('coverage:', (string) $r['reason']);
    }

    public function test_rounding_boundary_does_not_false_pass(): void
    {
        // REGRESSION (adversarial workflow): comparing a 4-decimal-ROUNDED coverage against a >4-decimal
        // floor false-PASSed. 5/9 = 0.55555... is BELOW floor 0.55556, but round(.,4)=0.5556 >= floor.
        // The fix compares the EXACT ratio, so this is correctly INCOMPLETE.
        $criteria = [];
        for ($i = 0; $i < 5; $i++) {
            $criteria[] = ['id' => 's'.$i, 'satisfied' => true, 'required' => false];
        }
        for ($i = 0; $i < 4; $i++) {
            $criteria[] = ['id' => 'u'.$i, 'satisfied' => false, 'required' => false];
        }
        $r = (new AtlasLoopCompletenessGate)->evaluate($criteria, 0.55556);
        $this->assertFalse($r['complete'], 'exact 5/9 < floor 0.55556 must be incomplete despite rounding to 0.5556');
    }
}
