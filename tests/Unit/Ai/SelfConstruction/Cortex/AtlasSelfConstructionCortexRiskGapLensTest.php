<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexRiskGapLens;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCortexRiskGapLens: clean facts ⇒ no gaps; every documented risk class
 * triggers when its input fact is present; envelope carries no scalar score / rank / hype field.
 */
final class AtlasSelfConstructionCortexRiskGapLensTest extends TestCase
{
    public function test_clean_posture_yields_no_gaps(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => []],
            'verification' => ['server_side_green' => true],
            'merge' => ['posture' => 'safe'],
            'queue_health' => ['malformed_count' => 0, 'repeated_give_back_count' => 0],
            'sweep_health' => ['coverage_unknown' => false],
            'knowledge_sync' => ['conformant' => true, 'blockers' => []],
        ]);
        $this->assertSame([], $r['gaps']);
    }

    public function test_stale_context_gap_triggered_by_missing_required_source(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:memory']],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_STALE_CONTEXT, $classes);
    }

    public function test_missing_receipts_gap_triggered_by_red_verification(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'verification' => ['server_side_green' => false],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_MISSING_RECEIPTS, $classes);
    }

    public function test_unsafe_merge_posture_gap_triggered(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'merge' => ['posture' => 'unsafe'],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNSAFE_MERGE, $classes);
    }

    public function test_malformed_queue_gap_triggered_by_positive_count(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['malformed_count' => 5],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_MALFORMED_QUEUE, $classes);
    }

    public function test_unproved_runtime_gap_triggered_by_unknown_coverage_or_repeated_giveback(): void
    {
        $r1 = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'sweep_health' => ['coverage_unknown' => true],
        ]);
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNPROVED_RUNTIME, array_column($r1['gaps'], 'class'));

        $r2 = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'queue_health' => ['repeated_give_back_count' => 4],
        ]);
        $this->assertContains(AtlasSelfConstructionCortexRiskGapLens::GAP_UNPROVED_RUNTIME, array_column($r2['gaps'], 'class'));
    }

    public function test_envelope_carries_no_scalar_score_or_rank_field(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:docs']],
            'queue_health' => ['malformed_count' => 1],
        ]);
        $json = (string) json_encode($r);
        $this->assertDoesNotMatchRegularExpression('/"(score|rank|grade|hype|percent)"/i', $json);
    }

    public function test_gaps_are_sorted_byte_stably_by_class(): void
    {
        $r = (new AtlasSelfConstructionCortexRiskGapLens)->project([
            'source_inventory' => ['blockers' => ['missing_required_source:queue']],
            'verification' => ['server_side_green' => false],
            'merge' => ['posture' => 'unknown'],
            'queue_health' => ['malformed_count' => 1, 'repeated_give_back_count' => 9],
            'sweep_health' => ['coverage_unknown' => true],
        ]);
        $classes = array_column($r['gaps'], 'class');
        $copy = $classes;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $classes);
    }
}
