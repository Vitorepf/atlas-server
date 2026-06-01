<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\SddMutationApprovalGateEvaluator;
use PHPUnit\Framework\TestCase;

final class SddMutationApprovalGateEvaluatorTest extends TestCase
{
    private SddMutationApprovalGateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new SddMutationApprovalGateEvaluator();
    }

    public function testApprovedPostExecuteWithMatchingAllowedPathPermitsWrite(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php', 'app/Services/Ai/Other.php'],
            ],
            ['passed' => true],
            ['granted' => true],
        );

        $this->assertSame('atlas.sdd.mutation_approval_gate.v1', $result['schema_version']);
        $this->assertSame('allowed', $result['verdict']);
        $this->assertTrue($result['write_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(
            ['sdd_decision_receipt', 'human_approval_receipt', 'mutation_write_receipt'],
            $result['required_receipts'],
        );
    }

    public function testMissingApprovalReturnsNeedsHumanApproval(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => true],
            ['granted' => false],
        );

        $this->assertSame('needs_human_approval', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(['human_approval_missing'], $result['blockers']);
        $this->assertSame(
            ['sdd_decision_receipt', 'human_approval_receipt'],
            $result['required_receipts'],
        );
    }

    public function testFailedGateBlocks(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => false],
            ['granted' => true],
        );

        $this->assertSame('blocked', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(['gate_failed'], $result['blockers']);
        $this->assertSame(
            ['sdd_decision_receipt', 'block_decision_receipt'],
            $result['required_receipts'],
        );
    }

    public function testTargetOutsideAllowedFilesBlocks(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Forbidden.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => true],
            ['granted' => true],
        );

        $this->assertSame('blocked', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(['target_outside_allowed_files'], $result['blockers']);
    }

    public function testReadOnlyDryRequestNeverWrites(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'dry',
                'target' => 'app/Services/Ai/Forbidden.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => false],
            ['granted' => false],
        );

        // Dry/read-only short-circuits: never writes, never blocked, never escalated,
        // even though the gate failed, approval is missing and the target is out of scope.
        $this->assertSame('allowed', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(['sdd_decision_receipt'], $result['required_receipts']);
    }

    public function testDryRunFlagSuppressesWriteIndependentOfMode(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'dry_run' => true,
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => true],
            ['granted' => true],
        );

        $this->assertSame('allowed', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testFailedGateAndOutOfScopeAccumulateBothBlockers(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Forbidden.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => false],
            ['granted' => true],
        );

        $this->assertSame('blocked', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(['gate_failed', 'target_outside_allowed_files'], $result['blockers']);
    }

    public function testMultiTargetWriteAllowedWhenEveryTargetInScope(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'targets' => ['app/A.php', 'app/B.php'],
                'allowed_files' => ['app/A.php', 'app/B.php', 'app/C.php'],
            ],
            ['passed' => true],
            ['granted' => true],
        );

        $this->assertSame('allowed', $result['verdict']);
        $this->assertTrue($result['write_allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMultiTargetBlocksWhenOneTargetOutOfScope(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'targets' => ['app/A.php', 'app/Z.php'],
                'allowed_files' => ['app/A.php', 'app/B.php'],
            ],
            ['passed' => true],
            ['granted' => true],
        );

        $this->assertSame('blocked', $result['verdict']);
        $this->assertSame(['target_outside_allowed_files'], $result['blockers']);
    }

    public function testGetMethodIsTreatedAsReadOnly(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'GET',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => [],
            ],
            ['passed' => false],
            ['granted' => false],
        );

        $this->assertSame('allowed', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
    }

    public function testUnsignedApprovalStillNeedsHumanApprovalWhenSignatureExpected(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['passed' => true],
            ['granted' => true, 'signed' => false],
        );

        $this->assertSame('needs_human_approval', $result['verdict']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(['human_approval_missing'], $result['blockers']);
    }

    public function testGateStatusStringPassedIsHonouredWithoutPassedFlag(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'method' => 'POST',
                'mode' => 'execute',
                'target' => 'app/Services/Ai/Demo.php',
                'allowed_files' => ['app/Services/Ai/Demo.php'],
            ],
            ['status' => 'passed'],
            ['granted' => true],
        );

        $this->assertSame('allowed', $result['verdict']);
        $this->assertTrue($result['write_allowed']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $request = [
            'method' => 'POST',
            'mode' => 'execute',
            'target' => 'app/Services/Ai/Demo.php',
            'allowed_files' => ['app/Services/Ai/Demo.php'],
        ];
        $gate = ['passed' => true];
        $approval = ['granted' => true];

        $first = $this->evaluator->evaluate($request, $gate, $approval);
        $second = $this->evaluator->evaluate($request, $gate, $approval);

        $this->assertSame($first, $second);
    }
}
