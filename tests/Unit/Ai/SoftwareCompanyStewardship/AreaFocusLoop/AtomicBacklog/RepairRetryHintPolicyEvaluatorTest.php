<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\RepairRetryHintPolicyEvaluator;
use PHPUnit\Framework\TestCase;

final class RepairRetryHintPolicyEvaluatorTest extends TestCase
{
    private RepairRetryHintPolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RepairRetryHintPolicyEvaluator();
    }

    public function testRetryWithFreshContextUnderMaxAttemptsRetries(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'retry_with_fresh_context'],
                'cycle_position' => 1,
            ],
            ['repair_loop_attempt_count' => 1],
            3,
        );

        $this->assertSame('atlas.repair.retry_hint_policy.v1', $result['schema_version']);
        $this->assertSame('retry', $result['decision']);
        $this->assertTrue($result['next_attempt_allowed']);
        $this->assertSame(2, $result['attempt_count_next']);
        $this->assertNull($result['stop_reason']);
        $this->assertTrue($result['evidence_required']);
    }

    public function testGatePassedReturnsResolved(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'gate' => ['passed' => true],
                'repair_hook' => ['hint' => 'retry_with_fresh_context'],
            ],
            ['repair_loop_attempt_count' => 1],
            3,
        );

        $this->assertSame('resolved', $result['decision']);
        $this->assertFalse($result['next_attempt_allowed']);
        $this->assertSame('gate_passed', $result['stop_reason']);
        $this->assertSame(1, $result['attempt_count_next']);
        $this->assertTrue($result['evidence_required']);
    }

    public function testEscalateToHumanStops(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'escalate_to_human'],
            ],
            ['repair_loop_attempt_count' => 0],
            3,
        );

        $this->assertSame('escalate', $result['decision']);
        $this->assertFalse($result['next_attempt_allowed']);
        $this->assertSame('escalate_to_human', $result['stop_reason']);
        $this->assertSame(0, $result['attempt_count_next']);
    }

    public function testRequestOperatorInputStops(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'request_operator_input'],
            ],
            ['repair_loop_attempt_count' => 1],
            3,
        );

        $this->assertSame('escalate', $result['decision']);
        $this->assertFalse($result['next_attempt_allowed']);
        $this->assertSame('request_operator_input', $result['stop_reason']);
    }

    public function testMaxAttemptsReturnsExhausted(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'retry_with_fresh_context'],
            ],
            ['repair_loop_attempt_count' => 3],
            3,
        );

        $this->assertSame('exhausted', $result['decision']);
        $this->assertFalse($result['next_attempt_allowed']);
        $this->assertSame('max_attempts_reached', $result['stop_reason']);
        $this->assertSame(3, $result['attempt_count_next']);
        $this->assertTrue($result['evidence_required']);
    }

    public function testNarrowScopeHintAlsoRetriesAndIncrementsAttempt(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'narrow_scope'],
            ],
            ['repair_loop_attempt_count' => 0],
            3,
        );

        $this->assertSame('retry', $result['decision']);
        $this->assertTrue($result['next_attempt_allowed']);
        $this->assertSame(1, $result['attempt_count_next']);
    }

    public function testAddTestsFirstHintRetriesUntilTheLastAllowedAttempt(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'add_tests_first'],
            ],
            ['repair_loop_attempt_count' => 4],
            5,
        );

        $this->assertSame('retry', $result['decision']);
        $this->assertSame(5, $result['attempt_count_next']);
        $this->assertTrue($result['next_attempt_allowed']);
    }

    public function testUnknownHintEscalatesAsUnrepairable(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'reboot_the_universe'],
            ],
            ['repair_loop_attempt_count' => 0],
            3,
        );

        $this->assertSame('escalate', $result['decision']);
        $this->assertFalse($result['next_attempt_allowed']);
        $this->assertSame('unrepairable_hint', $result['stop_reason']);
    }

    public function testMissingRepairHookEscalatesAsUnrepairable(): void
    {
        $result = $this->evaluator->evaluate(
            [],
            [],
            3,
        );

        $this->assertSame('escalate', $result['decision']);
        $this->assertSame('unrepairable_hint', $result['stop_reason']);
        $this->assertSame(0, $result['attempt_count_next']);
    }

    public function testGatePassedTakesPrecedenceOverEscalatingHint(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'gate' => ['passed' => true],
                'repair_hook' => ['hint' => 'escalate_to_human'],
            ],
            ['repair_loop_attempt_count' => 2],
            3,
        );

        $this->assertSame('resolved', $result['decision']);
        $this->assertSame('gate_passed', $result['stop_reason']);
    }

    public function testAttemptCountFallsBackToCyclePositionWhenStateOmitsIt(): void
    {
        $result = $this->evaluator->evaluate(
            [
                'repair_hook' => ['hint' => 'retry_with_fresh_context'],
                'cycle_position' => 2,
            ],
            [],
            3,
        );

        $this->assertSame('retry', $result['decision']);
        $this->assertSame(3, $result['attempt_count_next']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $failedCycle = [
            'repair_hook' => ['hint' => 'add_tests_first'],
            'cycle_position' => 1,
        ];
        $state = ['repair_loop_attempt_count' => 1];

        $first = $this->evaluator->evaluate($failedCycle, $state, 3);
        $second = $this->evaluator->evaluate($failedCycle, $state, 3);

        $this->assertSame($first, $second);
    }
}
