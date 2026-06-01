<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\RealCycleEvidenceCompletenessEvaluator;
use PHPUnit\Framework\TestCase;

final class RealCycleEvidenceCompletenessEvaluatorTest extends TestCase
{
    private RealCycleEvidenceCompletenessEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RealCycleEvidenceCompletenessEvaluator();
    }

    public function testFullMergedCycleCountsReal(): void
    {
        $result = $this->evaluator->evaluate($this->fullMergedCycle());

        $this->assertSame('atlas.loop.real_cycle_evidence_completeness.v1', $result['schema_version']);
        $this->assertTrue($result['counted_real']);
        $this->assertSame('counted_real', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
        $this->assertTrue($result['merge_truth_ok']);
        $this->assertTrue($result['quality_floor_passed']);
    }

    public function testEqualMainHashesFailMergeTruth(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['main_after'] = $cycle['main_before'];

        $result = $this->evaluator->evaluate($cycle);

        $this->assertFalse($result['merge_truth_ok']);
        $this->assertFalse($result['counted_real']);
        $this->assertSame('merge_truth_violation', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
    }

    public function testMissingLaneReceiptsFails(): void
    {
        $cycle = $this->fullMergedCycle();
        unset($cycle['lane_receipts']);

        $result = $this->evaluator->evaluate($cycle);

        $this->assertFalse($result['counted_real']);
        $this->assertSame('incomplete_real_attempt_evidence', $result['final_status']);
        $this->assertSame(['lane_receipts'], $result['missing_receipts']);
    }

    public function testProviderInvokedWithoutReceiptFails(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['provider_invoked'] = true;
        unset($cycle['provider_receipt_ref']);

        $result = $this->evaluator->evaluate($cycle);

        $this->assertFalse($result['counted_real']);
        $this->assertSame('incomplete_real_attempt_evidence', $result['final_status']);
        $this->assertSame(['provider_honest_state'], $result['missing_receipts']);
    }

    public function testProviderInvokedWithReceiptStaysReal(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['provider_invoked'] = true;
        $cycle['provider_receipt_ref'] = 'evidence://provider/run-9';

        $result = $this->evaluator->evaluate($cycle);

        $this->assertTrue($result['counted_real']);
        $this->assertSame('counted_real', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
    }

    public function testBlockedHonestAfterRealAttemptCountsReal(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['merge_performed'] = false;
        unset($cycle['main_before'], $cycle['main_after']);
        $cycle['blocker_receipt_ref'] = 'evidence://blocker/honest-stop-3';

        $result = $this->evaluator->evaluate($cycle);

        $this->assertTrue($result['counted_real']);
        $this->assertSame('blocked_honest_after_real_attempt', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
        $this->assertFalse($result['merge_truth_ok']);
    }

    public function testNoMergeWithoutBlockerReceiptDoesNotCountReal(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['merge_performed'] = false;
        unset($cycle['main_before'], $cycle['main_after']);

        $result = $this->evaluator->evaluate($cycle);

        $this->assertFalse($result['counted_real']);
        $this->assertSame('no_merge_no_blocker_receipt', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
    }

    public function testFailedJudgeDropsQualityFloorButKeepsReceiptPresent(): void
    {
        $cycle = $this->fullMergedCycle();
        $cycle['judge_passed'] = false;

        $result = $this->evaluator->evaluate($cycle);

        $this->assertFalse($result['quality_floor_passed']);
        $this->assertTrue($result['counted_real']);
        $this->assertSame('counted_real', $result['final_status']);
        $this->assertSame([], $result['missing_receipts']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $cycle = $this->fullMergedCycle();

        $first = $this->evaluator->evaluate($cycle);
        $second = $this->evaluator->evaluate($cycle);

        $this->assertSame($first, $second);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullMergedCycle(): array
    {
        return [
            'slice_plan_ref' => 'evidence://slice/AP-790',
            'lane_receipts' => ['evidence://lane/impl', 'evidence://lane/test'],
            'provider_honest' => true,
            'provider_invoked' => false,
            'branch_ref' => 'feature/ap-790',
            'worktree_ref' => '/tmp/worktrees/ap-790',
            'validation_receipt_ref' => 'evidence://validation/run-1',
            'validation_passed' => true,
            'judge_receipt_ref' => 'evidence://judge/run-1',
            'judge_passed' => true,
            'evidence_ref' => 'evidence://cycle/ap-790',
            'merge_performed' => true,
            'main_before' => 'a1b2c3d4',
            'main_after' => 'e5f6a7b8',
        ];
    }
}
