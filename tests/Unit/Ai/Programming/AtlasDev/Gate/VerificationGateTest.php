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
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
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

    private function scopePassed(): ScopeGuardReceipt
    {
        return (new ScopeGuard)->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::patch(
                "--- a/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n+++ b/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n@@ -1,1 +1,1 @@\n-old\n+new\n",
                ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            ),
        );
    }

    /**
     * Returns a scope receipt with NO file diffs, so the M1 floor computes no
     * additional commands. Use this for tests that want to exercise the gate's
     * core behavior (caller commands, noCommandsResult paths) without the floor
     * adding impacted-test commands.
     */
    private function scopePassedNoFloor(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-x',
            taskContractHash: 'test-hash',
            baseline: new ScopeBaseline(
                gitStatusBefore: 'clean',
                gitDiffBeforeHash: null,
            ),
            observed: new ScopeObserved(
                gitDiffHash: null,
                changedFiles: [],
                changedFilesCount: 0,
                fileDiffs: [],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: [],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 10,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'no_changes',
            userPreExistingChanges: [],
        );
    }

    public function test_passed_when_single_command_succeeds(): void
    {
        $runner = new FakeCommandRunner;
        // Queue result for the caller's command
        $runner->queue(new VerificationCommandResult(
            command: 'composer test', exitCode: 0,
            stdout: 'OK (1 test)', stderr: '', durationMs: 200,
        ));
        // Queue results for M1 floor commands (impacted test, php -l, pint)
        // since scopePassed() has file diffs that trigger floor
        $runner->queue(new VerificationCommandResult(
            command: 'floor-impacted-test', exitCode: 0,
            stdout: 'OK', stderr: '', durationMs: 100,
        ));
        $runner->queue(new VerificationCommandResult(
            command: 'php -l', exitCode: 0,
            stdout: 'No syntax errors', stderr: '', durationMs: 50,
        ));
        $runner->queue(new VerificationCommandResult(
            command: 'pint', exitCode: 0,
            stdout: 'OK', stderr: '', durationMs: 100,
        ));

        $result = $this->makeGate($runner)->run(
            taskContract: $this->taskContractFixture(),
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp/atlas-workspace',
        );

        $this->assertSame(VerificationGateResult::STATUS_PASSED, $result->aggregateStatus);
        // Multiple tests now due to floor commands
        $this->assertNotEmpty($result->tests);
        $this->assertTrue($result->tests[0]->ok);
        $this->assertSame(GateOutcome::STATUS_PASSED, $result->gates[0]->status);
    }

    public function test_failed_when_any_command_returns_non_zero(): void
    {
        $runner = new FakeCommandRunner;
        // Queue results for caller commands
        $runner->queue(new VerificationCommandResult('a', 0, 'ok', '', 100));
        $runner->queue(new VerificationCommandResult('b', 2, 'fail', 'err', 300));
        // Queue results for M1 floor commands
        $runner->queue(new VerificationCommandResult('floor', 0, 'ok', '', 100));
        $runner->queue(new VerificationCommandResult('floor', 0, 'ok', '', 100));
        $runner->queue(new VerificationCommandResult('floor', 0, 'ok', '', 100));

        $task = $this->taskContractFixture(['validation_commands' => ['a', 'b']]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassed(),
            workspace: '/tmp/atlas-workspace',
        );

        $this->assertSame(VerificationGateResult::STATUS_FAILED, $result->aggregateStatus);
        // More than 2 tests now due to floor commands
        $this->assertGreaterThanOrEqual(2, count($result->tests));
        // Find the test results for commands 'a' and 'b'
        $testsA = array_filter($result->tests, fn ($t) => $t->command === 'a');
        $testsB = array_filter($result->tests, fn ($t) => $t->command === 'b');
        $this->assertNotEmpty($testsA, 'Command a should have run');
        $this->assertNotEmpty($testsB, 'Command b should have run');
        $this->assertTrue(array_values($testsA)[0]->ok, 'Command a should pass');
        $this->assertFalse(array_values($testsB)[0]->ok, 'Command b should fail');
        $this->assertSame(GateOutcome::STATUS_FAILED, $result->gates[0]->status);
    }

    public function test_passed_with_skip_when_no_commands_but_explicit_no_test_reason(): void
    {
        $runner = new FakeCommandRunner;
        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'no_test_reason' => 'risk_assessment_only_no_runtime_impact',
        ]);

        // Use scopePassedNoFloor() to test the legacy noCommandsResult path
        // without M1 floor commands being added.
        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassedNoFloor(),
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
        $runner = new FakeCommandRunner;
        $task = $this->taskContractFixture(['validation_commands' => []]);

        // Use scopePassedNoFloor() to test the legacy noCommandsResult path
        // without M1 floor commands being added.
        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $this->scopePassedNoFloor(),
            workspace: '/tmp',
        );

        $this->assertSame(VerificationGateResult::STATUS_NEEDS_REVIEW, $result->aggregateStatus);
        $this->assertContains('test_skipped_no_reason', $result->honestyFlags);
        $this->assertSame(GateOutcome::STATUS_NEEDS_REVIEW, $result->gates[0]->status);
    }

    public function test_dangerous_command_is_rejected_and_flagged(): void
    {
        $runner = new FakeCommandRunner;
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
        $runner = new FakeCommandRunner;
        $runner->queue(new VerificationCommandResult('composer test', 0, 'ok', '', 100));

        $scope = (new ScopeGuard)->check(
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
