<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\PhaseAdvanceVerdictClassifier;
use Tests\TestCase;

final class PhaseAdvanceVerdictClassifierTest extends TestCase
{
    private PhaseAdvanceVerdictClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new PhaseAdvanceVerdictClassifier();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['a'], 'passed' => ['a'], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('atlas.aaeos.phase_advance_verdict.v1', $result['schema_version']);
        $this->assertSame('advance', $result['verdict']);
        $this->assertSame('phase_advance_ready', $result['reason']);
        $this->assertSame([], $result['missing_gates']);
        $this->assertSame([], $result['blocked_gates']);
        $this->assertSame([], $result['high_blocker_ids']);
    }

    // (1) PRECEDENCE: high-severity blocker AND missing required gates => 'block'.
    public function testHighSeverityBlockerOutranksRepairAndBlocks(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['build', 'lint'], 'passed' => ['build'], 'blocked' => []],
            'blockers' => [
                ['id' => 'sec-1', 'severity' => 'high', 'owner' => 'security'],
            ],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame('high_severity_blocker_block', $result['reason']);
        $this->assertNotSame([], $result['high_blocker_ids']);
        $this->assertSame(['sec-1'], $result['high_blocker_ids']);
        $this->assertNotSame([], $result['missing_gates']);
        $this->assertSame(['lint'], $result['missing_gates']);
    }

    // (2) HALT: policy_gate missing the decision token, no high blocker => 'halt' mentioning policy.
    public function testPolicyGateWithoutDecisionTokenHaltsAndMentionsPolicy(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => ['some_other_gate'],
                'blocked' => [],
            ],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('halt', $result['verdict']);
        $this->assertStringContainsString('policy', $result['reason']);
    }

    // (2) The SAME phase WITH the decision token passed and no blockers => 'advance'.
    public function testPolicyGateWithDecisionTokenPassedAdvances(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => ['policy_decision_allowed_true'],
                'blocked' => [],
            ],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('advance', $result['verdict']);
    }

    // (3) SIGNATURE: receipt phase, all gates passed, zero blockers, null signature => 'block'.
    public function testReceiptWithoutOperatorSignatureBlocks(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'receipt',
            'gates' => ['required' => ['build'], 'passed' => ['build'], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => null,
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertStringContainsString('operator_signature_required', $result['reason']);
    }

    // (3) Flipping operator_signature to a non-empty string flips the SAME envelope to 'advance'.
    public function testReceiptWithOperatorSignatureAdvances(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'receipt',
            'gates' => ['required' => ['build'], 'passed' => ['build'], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => 'operator-approved',
        ]);

        $this->assertSame('advance', $result['verdict']);
    }

    // (4) REPAIR via blocked gates: all required passed, no blockers, signature present => 'repair'.
    public function testBlockedGatesYieldRepairWithBlockedGatesEcho(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['build'], 'passed' => ['build'], 'blocked' => ['x']],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('repair', $result['verdict']);
        $this->assertSame(['x'], $result['blocked_gates']);
    }

    // (5) spec required=['a','b'] passed=['b'] => 'repair' and missing_gates EXACTLY ['a'].
    public function testSpecPartialCoverageRepairsWithRequiredOrderPreserved(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['a', 'b'], 'passed' => ['b'], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('repair', $result['verdict']);
        $this->assertSame(['a'], $result['missing_gates']);
    }

    // (5) passed=['a','b'] with empty blocked/blockers => 'advance' and missing_gates===[].
    public function testSpecFullCoverageAdvancesWithEmptyMissingGates(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['a', 'b'], 'passed' => ['a', 'b'], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('advance', $result['verdict']);
        $this->assertSame([], $result['missing_gates']);
    }

    public function testMissingGatesPreserveRequiredOrderNotPassedOrder(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => [
                'required' => ['security', 'build', 'lint', 'typecheck'],
                'passed' => ['lint'],
                'blocked' => [],
            ],
            'blockers' => [],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('repair', $result['verdict']);
        $this->assertSame(['security', 'build', 'typecheck'], $result['missing_gates']);
    }

    public function testNonHighBlockerWithoutMissingGatesYieldsRepair(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['build'], 'passed' => ['build'], 'blocked' => []],
            'blockers' => [
                ['id' => 'flake-1', 'severity' => 'medium', 'owner' => 'qa'],
            ],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('repair', $result['verdict']);
        $this->assertSame('open_blockers_repair', $result['reason']);
        $this->assertSame([], $result['high_blocker_ids']);
    }

    public function testHighBlockerBeatsPolicyHaltCondition(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => [],
                'blocked' => [],
            ],
            'blockers' => [
                ['id' => 'risk-9', 'severity' => 'high', 'owner' => 'risk'],
            ],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['risk-9'], $result['high_blocker_ids']);
    }

    public function testOnlyHighSeverityBlockerIdsAreCollected(): void
    {
        $result = $this->classifier->classify([
            'phase_out' => 'spec',
            'gates' => ['required' => ['build'], 'passed' => ['build'], 'blocked' => []],
            'blockers' => [
                ['id' => 'low-1', 'severity' => 'low', 'owner' => 'qa'],
                ['id' => 'high-1', 'severity' => 'high', 'owner' => 'security'],
                ['id' => 'high-2', 'severity' => 'HIGH', 'owner' => 'security'],
                ['id' => 'med-1', 'severity' => 'medium', 'owner' => 'qa'],
            ],
            'operator_signature' => 'op-1',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['high-1', 'high-2'], $result['high_blocker_ids']);
    }

    public function testSevenOrderedPrecedenceRulesAreDefined(): void
    {
        $rules = $this->precedenceRules();

        $this->assertCount(7, $rules);
        $this->assertSame(
            [
                'high_severity_blocker_block',
                'policy_decision_not_allowed_halt',
                'operator_signature_required',
                'open_blockers_repair',
                'missing_required_gates_repair',
                'blocked_gates_repair',
                'phase_advance_ready',
            ],
            $rules,
        );
    }

    public function testIdenticalEnvelopeIsDeterministic(): void
    {
        $envelope = [
            'phase_out' => 'spec',
            'gates' => ['required' => ['a', 'b'], 'passed' => ['b'], 'blocked' => ['x']],
            'blockers' => [
                ['id' => 'high-1', 'severity' => 'high', 'owner' => 'security'],
            ],
            'operator_signature' => null,
        ];

        $first = $this->classifier->classify($envelope);
        $second = $this->classifier->classify($envelope);

        $this->assertSame($first, $second);
    }

    /**
     * @return list<string>
     */
    private function precedenceRules(): array
    {
        $reflection = new \ReflectionClass(PhaseAdvanceVerdictClassifier::class);

        /** @var list<string> $rules */
        $rules = $reflection->getConstant('RULES');

        return $rules;
    }
}
