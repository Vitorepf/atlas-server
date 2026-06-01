<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\RegressionRevertDecisionEvaluator;
use PHPUnit\Framework\TestCase;

final class RegressionRevertDecisionEvaluatorTest extends TestCase
{
    private RegressionRevertDecisionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RegressionRevertDecisionEvaluator();
    }

    public function testMeasuredRegressionAfterAppliedLearningChoosesRevertNoEdit(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => true],
            [
                'dirty_human_work' => false,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('atlas.loop.regression_revert_decision.v1', $result['schema_version']);
        $this->assertSame('revert', $result['decision']);
        $this->assertTrue($result['revert_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('revert_no_edit', $result['git_operation']);
    }

    public function testNoRegressionChoosesNoOp(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => false],
            [
                'dirty_human_work' => false,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('atlas.loop.regression_revert_decision.v1', $result['schema_version']);
        $this->assertSame('no_op', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testNoAppliedLearningChoosesNoOpEvenWithRegressionSignal(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => false],
            ['regression_detected' => true],
            [
                'dirty_human_work' => false,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('no_op', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testDirtyHumanWorkBlocksRevert(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => true],
            [
                'dirty_human_work' => true,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('block_revert', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame(['dirty_human_work'], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testMissingRevertPortBlocksRevert(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => true],
            [
                'dirty_human_work' => false,
                'revert_port_available' => false,
            ],
        );

        $this->assertSame('block_revert', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame(['missing_revert_port'], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testDirtyWorkAndMissingPortAccumulateBothBlockersInOrder(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => true],
            [
                'dirty_human_work' => true,
                'revert_port_available' => false,
            ],
        );

        $this->assertSame('block_revert', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame(['dirty_human_work', 'missing_revert_port'], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testRegressionInferredFromScoreDropChoosesRevertNoEdit(): void
    {
        $result = $this->evaluator->decide(
            ['applied_ref' => 'learning-7f2a'],
            [
                'score_before' => 92,
                'score_after' => 71,
            ],
            [
                'uncommitted_human_changes' => 0,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('revert', $result['decision']);
        $this->assertTrue($result['revert_allowed']);
        $this->assertSame('revert_no_edit', $result['git_operation']);
        $this->assertSame([], $result['blockers']);
    }

    public function testScoreImprovementChoosesNoOp(): void
    {
        $result = $this->evaluator->decide(
            ['applied_ref' => 'learning-7f2a'],
            [
                'score_before' => 71,
                'score_after' => 92,
            ],
            [
                'uncommitted_human_changes' => 0,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('no_op', $result['decision']);
        $this->assertFalse($result['revert_allowed']);
        $this->assertSame('none', $result['git_operation']);
        $this->assertSame([], $result['blockers']);
    }

    public function testUncommittedHumanChangesCountBlocksRevert(): void
    {
        $result = $this->evaluator->decide(
            ['applied' => true],
            ['regression_detected' => true],
            [
                'uncommitted_human_changes' => 3,
                'revert_port_available' => true,
            ],
        );

        $this->assertSame('block_revert', $result['decision']);
        $this->assertSame(['dirty_human_work'], $result['blockers']);
        $this->assertSame('none', $result['git_operation']);
    }

    public function testResetHardIsNeverEmittedAcrossAllBranches(): void
    {
        $scenarios = [
            [
                ['applied' => true],
                ['regression_detected' => true],
                ['dirty_human_work' => false, 'revert_port_available' => true],
            ],
            [
                ['applied' => true],
                ['regression_detected' => false],
                ['dirty_human_work' => false, 'revert_port_available' => true],
            ],
            [
                ['applied' => true],
                ['regression_detected' => true],
                ['dirty_human_work' => true, 'revert_port_available' => true],
            ],
            [
                ['applied' => true],
                ['regression_detected' => true],
                ['dirty_human_work' => false, 'revert_port_available' => false],
            ],
            [
                ['applied' => false],
                ['regression_detected' => true],
                ['dirty_human_work' => false, 'revert_port_available' => false],
            ],
        ];

        foreach ($scenarios as $scenario) {
            [$applied, $measurement, $workspace] = $scenario;
            $result = $this->evaluator->decide($applied, $measurement, $workspace);

            $this->assertNotSame('reset_hard', $result['git_operation']);
            $this->assertContains($result['git_operation'], ['revert_no_edit', 'none']);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $appliedLearning = ['applied' => true];
        $measurement = ['regression_detected' => true];
        $workspaceState = [
            'dirty_human_work' => false,
            'revert_port_available' => true,
        ];

        $first = $this->evaluator->decide($appliedLearning, $measurement, $workspaceState);
        $second = $this->evaluator->decide($appliedLearning, $measurement, $workspaceState);

        $this->assertSame($first, $second);
    }
}
