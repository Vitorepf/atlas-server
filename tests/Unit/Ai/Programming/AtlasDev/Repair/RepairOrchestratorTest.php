<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairAttemptLimits;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairLoopResult;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use PHPUnit\Framework\TestCase;

final class RepairOrchestratorTest extends TestCase
{
    private function orchestrator(): RepairOrchestrator
    {
        return new RepairOrchestrator(
            limits: new RepairAttemptLimits(),
            capsuleBuilder: new FailureCapsuleBuilder(new FailureSignatureHasher()),
            promptComposer: new RepairPromptComposer(),
        );
    }

    public function test_first_fail_then_pass_recovers(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 2);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            RepairAttemptOutcome::passed('sha:fix', ['app/Foo.php'], 4),
        ]);

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_RECOVERED, $result->status);
        $this->assertSame(1, $result->attemptsExecuted);
        $this->assertSame(2, $result->attemptsAllowed);
        $this->assertSame(1, $evaluator->calls);
        $this->assertSame('claude_cli', $evaluator->observed[0]['provider']);
        $this->assertSame('sonnet', $evaluator->observed[0]['model']);
    }

    public function test_same_signature_twice_escalates_without_consuming_full_budget(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 3);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(
            taskContractHash: $contract->taskContractHash,
            primaryError: 'AssertionError: expected 1 got 0',
        );

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_FAILED,
                gate: 'verification_gate',
                command: 'composer test',
                exitCode: 1,
                primaryErrorExcerpt: 'AssertionError: expected 1 got 0', // same signature
                fullErrorLogPath: null,
                failingTest: 'tests/FooTest::test_x',
                diffHash: 'sha:b',
                changedFiles: ['app/Foo.php'],
                diffSizeLines: 3,
            ),
        ]);

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_ESCALATED, $result->status);
        $this->assertSame(1, $result->attemptsExecuted);
        $this->assertContains(
            FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE,
            $result->escalationSignalDelta,
        );
        $this->assertCount(2, $result->capsules);
        $this->assertSame(FailureCapsule::DECISION_ESCALATE, $result->lastCapsule->decision);
    }

    public function test_r4_returns_not_attempted_without_calling_evaluator(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 5);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([]); // would throw if called

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R4',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_NOT_ATTEMPTED, $result->status);
        $this->assertSame(0, $result->attemptsExecuted);
        $this->assertSame(0, $result->attemptsAllowed);
        $this->assertSame(0, $evaluator->calls);
        $this->assertContains('risk_level_forbids_repair', $result->escalationSignalDelta);
    }

    public function test_r5_returns_not_attempted(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 5);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R5',
            evaluator: RepairFixtureFactory::scriptedEvaluator([]),
        );

        $this->assertSame(RepairLoopResult::STATUS_NOT_ATTEMPTED, $result->status);
    }

    public function test_max_attempts_exhausted_returns_exhausted(): void
    {
        // R3 → up to 2 attempts. After 2 distinct failures we exhaust.
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 2);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(
            taskContractHash: $contract->taskContractHash,
            primaryError: 'error one',
        );

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_FAILED,
                gate: 'verification_gate',
                command: 'composer test',
                exitCode: 1,
                primaryErrorExcerpt: 'error two — entirely different cause',
                fullErrorLogPath: null,
                failingTest: null,
                diffHash: 'sha:b',
                changedFiles: ['app/Foo.php'],
                diffSizeLines: 5,
            ),
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_FAILED,
                gate: 'verification_gate',
                command: 'composer test',
                exitCode: 1,
                primaryErrorExcerpt: 'error three — yet another distinct cause',
                fullErrorLogPath: null,
                failingTest: null,
                diffHash: 'sha:c',
                changedFiles: ['app/Foo.php'],
                diffSizeLines: 6,
            ),
        ]);

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R3',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_EXHAUSTED, $result->status);
        $this->assertSame(2, $result->attemptsExecuted);
        $this->assertSame(FailureCapsule::DECISION_STOP, $result->lastCapsule->decision);
    }

    public function test_scope_violation_outcome_short_circuits_to_escalated(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 3);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $initial = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            new RepairAttemptOutcome(
                status: RepairAttemptOutcome::STATUS_SCOPE_VIOLATION,
                gate: 'scope_guard_light',
                command: null,
                exitCode: null,
                primaryErrorExcerpt: 'attempt touched config/secrets.php which is forbidden',
                fullErrorLogPath: null,
                failingTest: null,
                diffHash: 'sha:b',
                changedFiles: ['app/Foo.php', 'config/secrets.php'],
                diffSizeLines: 7,
            ),
        ]);

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(RepairLoopResult::STATUS_ESCALATED, $result->status);
        $this->assertContains(
            FailureCapsuleBuilder::SIGNAL_SCOPE_VIOLATION,
            $result->escalationSignalDelta,
        );
    }

    public function test_evaluator_observes_preserved_allowed_files(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(
            maxAttempts: 2,
            allowedFiles: ['app/Foo.php', 'tests/Unit/FooTest.php'],
        );
        $prompt = RepairFixtureFactory::providerPromptProjection(
            taskContractHash: $contract->taskContractHash,
            allowedFiles: ['app/Foo.php', 'tests/Unit/FooTest.php'],
        );
        $initial = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $evaluator = RepairFixtureFactory::scriptedEvaluator([
            RepairAttemptOutcome::passed('sha:fix', ['app/Foo.php'], 2),
        ]);

        $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: $evaluator,
        );

        $this->assertSame(
            ['app/Foo.php', 'tests/Unit/FooTest.php'],
            $evaluator->observed[0]['allowed_files'],
            'Repair prompt must NEVER expand allowed_files.',
        );
    }

    public function test_initial_escalate_capsule_returns_escalated_with_zero_attempts(): void
    {
        $contract = RepairFixtureFactory::lightTaskContract(maxAttempts: 2);
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        // Initial capsule already declared escalate (e.g. preflight detected
        // scope_explosion before any attempt happened).
        $initial = FailureCapsule::issue(
            runId: 'run-repair-1',
            taskContractHash: $contract->taskContractHash,
            attemptIndex: 0,
            gate: 'scope_guard_light',
            command: null,
            exitCode: null,
            primaryErrorExcerpt: 'preflight detected scope explosion',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: null,
            changedFiles: ['app/Foo.php', 'config/secrets.php'],
            decision: FailureCapsule::DECISION_ESCALATE,
            escalationSignalDelta: ['scope_explosion_preflight'],
        );

        $result = $this->orchestrator()->run(
            contract: $contract,
            originalPrompt: $prompt,
            initialCapsule: $initial,
            riskLevel: 'R2',
            evaluator: RepairFixtureFactory::scriptedEvaluator([]),
        );

        $this->assertSame(RepairLoopResult::STATUS_ESCALATED, $result->status);
        $this->assertSame(0, $result->attemptsExecuted);
    }
}
