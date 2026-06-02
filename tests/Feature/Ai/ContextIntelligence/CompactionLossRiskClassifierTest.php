<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\CompactionLossRiskClassifier;
use Tests\TestCase;

final class CompactionLossRiskClassifierTest extends TestCase
{
    public function test_full_coverage_no_critical_no_discards_is_low_and_write_allowed(): void
    {
        $result = (new CompactionLossRiskClassifier())->classify(1.0, false, 0);

        $this->assertSame('atlas.context_intelligence.compaction_loss_risk.v1', $result['schema_version']);
        $this->assertSame('low', $result['loss_risk']);
        $this->assertTrue($result['write_allowed']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_low_coverage_with_critical_touch_is_high_and_write_blocked(): void
    {
        $result = (new CompactionLossRiskClassifier())->classify(0.9, true, 0);

        $this->assertSame('high', $result['loss_risk']);
        $this->assertFalse($result['write_allowed']);
        $this->assertContains('touched_critical_keep_kind', $result['reasons']);
    }

    public function test_partial_coverage_with_one_discard_is_medium_and_lists_both_conditions(): void
    {
        $result = (new CompactionLossRiskClassifier())->classify(0.95, false, 1);

        $this->assertSame('medium', $result['loss_risk']);
        $this->assertFalse($result['write_allowed']);
        $this->assertContains('coverage_below_1_0', $result['reasons']);
        $this->assertContains('forced_discards_present', $result['reasons']);
    }

    public function test_full_coverage_with_discards_is_medium_via_discard_branch_yet_write_allowed(): void
    {
        // Rule (2) is an OR: coverage<1.0 OR forcedDiscardCount>0 -> medium.
        // Here coverage is full (1.0) so the coverage clause is false; only the
        // forced-discards clause fires. The band must still be medium with
        // forced_discards_present as the SOLE reason (no coverage reasons). A
        // regression that dropped the discard clause would mislabel this 'low'
        // and pass every other case. Crucially, write_allowed stays true: the
        // write gate keys on coverage>=1.0 AND not-critical, independent of
        // forced discards, so a medium band can still permit the write.
        $result = (new CompactionLossRiskClassifier())->classify(1.0, false, 1);

        $this->assertSame('medium', $result['loss_risk']);
        $this->assertSame(['forced_discards_present'], $result['reasons']);
        $this->assertTrue($result['write_allowed']);
    }

    public function test_coverage_below_floor_is_high_and_lists_below_0_85_reason(): void
    {
        $result = (new CompactionLossRiskClassifier())->classify(0.8, false, 0);

        $this->assertSame('high', $result['loss_risk']);
        $this->assertContains('coverage_below_0_85', $result['reasons']);
    }

    public function test_coverage_exactly_at_floor_is_medium_not_high_proving_strict_cutoff(): void
    {
        $result = (new CompactionLossRiskClassifier())->classify(0.85, false, 0);

        $this->assertSame('medium', $result['loss_risk']);
        $this->assertNotSame('high', $result['loss_risk']);
        $this->assertNotContains('coverage_below_0_85', $result['reasons']);
    }

    public function test_critical_touch_vetoes_write_even_at_full_coverage_and_is_sole_reason(): void
    {
        $critical = (new CompactionLossRiskClassifier())->classify(1.0, true, 0);

        // Highest-precedence rule: a touched critical keep kind forces high
        // and blocks the write independent of coverage. With coverage at 1.0
        // and zero discards, no coverage/discard reasons may fire — the
        // critical touch must be the sole reason. A regression that made the
        // veto coverage-dependent would pass every other case here.
        $this->assertSame('high', $critical['loss_risk']);
        $this->assertFalse($critical['write_allowed']);
        $this->assertSame(['touched_critical_keep_kind'], $critical['reasons']);

        // Control: identical inputs minus the critical touch flip to the
        // write-allowed low band, proving the veto alone drives the block.
        $clean = (new CompactionLossRiskClassifier())->classify(1.0, false, 0);
        $this->assertSame('low', $clean['loss_risk']);
        $this->assertTrue($clean['write_allowed']);
    }
}
