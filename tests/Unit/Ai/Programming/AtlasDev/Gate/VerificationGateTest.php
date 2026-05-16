<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

final class VerificationGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function makeGate(FakeCommandRunner $runner): VerificationGate
    {
        return new VerificationGate($runner);
    }

    private function callResult(): ProviderCallResult
    {
        return ProviderCallResult::fromStdout(
            runId: 'run-x', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'ok', stderr: '', durationMs: 100,
        );
    }

    private function scopePassed(): \App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt
    {
        return (new ScopeGuard())->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::patch(
                "--- a/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n+++ b/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n@@ -1,1 +1,1 @@\n-old\n+new\n",
                ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            ),
        );
    }

    public function test_passed_when_single_command_succeeds(): void
    {
        $runner = new FakeCommandRunner();
        $runner->queue(new VerificationCommandResult(
            command: 'composer test', exitCode: 0,
            stdout: 'OK (1 test)', stderr: '', durationMs: 200,
        ));

        $result = $this->makeGate($runner)->run(
            taskContract: $this->taskContractFixture(),
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp/atlas-workspace',
        );

        $this->assertSame(VerificationGateResult::STATUS_PASSED, $result->aggregateStatus);
        $this->assertCount(1, $result->tests);
        $this->assertTrue($result->tests[0]->ok);
        $this->assertSame(GateOutcome::STATUS_PASSED, $result->gates[0]->status);
        $this->assertCount(1, $result->evidenceRefs);
    }

    public function test_failed_when_any_command_returns_non_zero(): void
    {
        $runner = new FakeCommandRunner();
        $runner->queue(new VerificationCommandResult('a', 0, 'ok', '', 100));
        $runner->queue(new VerificationCommandResult('b', 2, 'fail', 'err', 300));

        $task = $this->taskContractFixture(['validation_commands' => ['a', 'b']]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp/atlas-workspace',
        );

        $this->assertSame(VerificationGateResult::STATUS_FAILED, $result->aggregateStatus);
        $this->assertCount(2, $result->tests);
        $this->assertTrue($result->tests[0]->ok);
        $this->assertFalse($result->tests[1]->ok);
        $this->assertSame(GateOutcome::STATUS_FAILED, $result->gates[0]->status);
    }

    public function test_passed_with_skip_when_no_commands_but_explicit_no_test_reason(): void
    {
        $runner = new FakeCommandRunner();
        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'no_test_reason' => 'risk_assessment_only_no_runtime_impact',
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp',
        );

        $this->assertSame(VerificationGateResult::STATUS_PASSED, $result->aggregateStatus);
        $this->assertSame(GateOutcome::STATUS_SKIPPED, $result->gates[0]->status);
        $this->assertNotEmpty(array_filter(
            $result->honestyFlags,
            fn (string $f) => str_starts_with($f, 'no_tests_explicit_reason'),
        ));
        $this->assertSame([], $runner->calls);
    }

    public function test_needs_review_when_no_commands_and_no_reason(): void
    {
        $runner = new FakeCommandRunner();
        $task = $this->taskContractFixture(['validation_commands' => []]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp',
        );

        $this->assertSame(VerificationGateResult::STATUS_NEEDS_REVIEW, $result->aggregateStatus);
        $this->assertContains('test_skipped_no_reason', $result->honestyFlags);
        $this->assertSame(GateOutcome::STATUS_NEEDS_REVIEW, $result->gates[0]->status);
    }

    public function test_dangerous_command_is_rejected_and_flagged(): void
    {
        $runner = new FakeCommandRunner();
        $task = $this->taskContractFixture(['validation_commands' => ['rm -rf /']]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp',
        );

        $this->assertSame(VerificationGateResult::STATUS_FAILED, $result->aggregateStatus);
        $this->assertFalse($result->tests[0]->ok);
        $this->assertNotEmpty(array_filter(
            $result->honestyFlags,
            fn (string $f) => str_starts_with($f, 'verification_command_rejected'),
        ));
    }

    public function test_propagates_honesty_flag_when_scope_guard_failed(): void
    {
        $runner = new FakeCommandRunner();
        $runner->queue(new VerificationCommandResult('composer test', 0, 'ok', '', 100));

        $scope = (new ScopeGuard())->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::patch(
                "--- a/vendor/laravel/x.php\n+++ b/vendor/laravel/x.php\n@@\n-old\n+new\n",
                ['vendor/laravel/x.php'],
            ),
        );

        $result = $this->makeGate($runner)->run(
            taskContract: $this->taskContractFixture(),
            callResult: $this->callResult(),
            scopeReceipt: $scope,
            workspace: '/tmp',
        );

        $this->assertContains('verification_ran_with_scope_guard_failed', $result->honestyFlags);
    }
}
