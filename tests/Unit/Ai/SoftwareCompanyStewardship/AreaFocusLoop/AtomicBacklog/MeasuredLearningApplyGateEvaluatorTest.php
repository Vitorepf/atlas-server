<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\MeasuredLearningApplyGateEvaluator;
use PHPUnit\Framework\TestCase;

final class MeasuredLearningApplyGateEvaluatorTest extends TestCase
{
    private MeasuredLearningApplyGateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new MeasuredLearningApplyGateEvaluator();
    }

    public function testProvenPositiveLiftWithRsiGatePassPermitsApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => false,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => true,
                'lift' => 0.12,
                'rollback_plan' => 'git revert to checkpoint sha',
            ],
            [
                'rsi_meta_judge' => 'pass',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertSame('atlas.loop.measured_learning_apply_gate.v1', $result['schema_version']);
        $this->assertTrue($result['apply_allowed']);
        $this->assertSame('apply', $result['decision']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('apply_via_mutation_runtime', $result['required_next_action']);
    }

    public function testApprovedProposalWithRsiGatePassPermitsApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => true,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => false,
                'lift' => 0.0,
                'rollback_proven' => true,
            ],
            [
                'rsi_meta_judge_passed' => true,
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertTrue($result['apply_allowed']);
        $this->assertSame('apply', $result['decision']);
        $this->assertSame([], $result['blockers']);
    }

    public function testUnprovenProposalBlocksApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => false,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => false,
                'lift' => 0.0,
                'rollback_plan' => 'git revert to checkpoint sha',
            ],
            [
                'rsi_meta_judge' => 'pass',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame('hold', $result['decision']);
        $this->assertSame(['not_approved_or_proven'], $result['blockers']);
        $this->assertSame('remediate_blockers', $result['required_next_action']);
    }

    public function testNonPositiveLiftDoesNotCountAsProven(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => false,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => true,
                'lift' => 0.0,
                'rollback_plan' => 'git revert to checkpoint sha',
            ],
            [
                'rsi_meta_judge' => 'pass',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame(['not_approved_or_proven'], $result['blockers']);
    }

    public function testRsiMetaJudgeFailBlocksApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => true,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => true,
                'lift' => 0.20,
                'rollback_plan' => 'git revert to checkpoint sha',
            ],
            [
                'rsi_meta_judge' => 'fail',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame('hold', $result['decision']);
        $this->assertSame(['rsi_meta_judge_failed'], $result['blockers']);
        $this->assertSame('remediate_blockers', $result['required_next_action']);
    }

    public function testMissingRollbackPlanBlocksApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => true,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
            ],
            [
                'proven' => true,
                'lift' => 0.15,
            ],
            [
                'rsi_meta_judge' => 'pass',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame('hold', $result['decision']);
        $this->assertSame(['rollback_proof_missing'], $result['blockers']);
    }

    public function testScopeOutsideAllowedPathsBlocksApply(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => true,
                'scope_paths' => ['app/Services/Ai/Loop/Handler.php', 'config/atlas.php'],
            ],
            [
                'proven' => true,
                'lift' => 0.18,
                'rollback_plan' => 'git revert to checkpoint sha',
            ],
            [
                'rsi_meta_judge' => 'pass',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame('hold', $result['decision']);
        $this->assertSame(['scope_out_of_bounds'], $result['blockers']);
    }

    public function testMultipleFailuresAccumulateInRuleOrder(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'approved' => false,
                'scope_paths' => ['secret/keys.php'],
            ],
            [
                'proven' => false,
            ],
            [
                'rsi_meta_judge' => 'fail',
                'allowed_paths' => ['app/Services/Ai/Loop'],
            ],
        );

        $this->assertFalse($result['apply_allowed']);
        $this->assertSame(
            ['not_approved_or_proven', 'rsi_meta_judge_failed', 'scope_out_of_bounds', 'rollback_proof_missing'],
            $result['blockers'],
        );
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proposal = [
            'approved' => true,
            'scope_paths' => ['app/Services/Ai/Loop/Handler.php'],
        ];
        $proof = [
            'proven' => true,
            'lift' => 0.12,
            'rollback_plan' => 'git revert to checkpoint sha',
        ];
        $gate = [
            'rsi_meta_judge' => 'pass',
            'allowed_paths' => ['app/Services/Ai/Loop'],
        ];

        $first = $this->evaluator->evaluate($proposal, $proof, $gate);
        $second = $this->evaluator->evaluate($proposal, $proof, $gate);

        $this->assertSame($first, $second);
    }
}
