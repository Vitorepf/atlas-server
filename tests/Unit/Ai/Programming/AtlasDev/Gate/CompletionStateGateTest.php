<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

final class CompletionStateGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function gate(): CompletionStateGate
    {
        return new CompletionStateGate();
    }

    private function happyCall(): ProviderCallResult
    {
        return ProviderCallResult::fromStdout(
            runId: 'run', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'x', stderr: '', durationMs: 100,
        );
    }

    private function scopePassed(): \App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt
    {
        return (new ScopeGuard())->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::patch(
                "--- a/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n+++ b/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php\n@@\n-old\n+new\n",
                ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            ),
        );
    }

    private function scopeFailed(): \App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt
    {
        return (new ScopeGuard())->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::patch(
                "--- a/vendor/x.php\n+++ b/vendor/x.php\n@@\n-old\n+new\n",
                ['vendor/x.php'],
            ),
        );
    }

    private function verificationPassed(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [new TestRun('composer test', true, 0, 100, hash('sha256', 'ok'), null)],
            gates: [new GateOutcome('verification_gate', GateOutcome::STATUS_PASSED, true, null, true, null)],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
            profile: VerificationGate::PROFILE_PHP_LARAVEL,
        );
    }

    private function verificationFailed(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [new TestRun('composer test', false, 2, 100, hash('sha256', 'err'), null)],
            gates: [new GateOutcome('verification_gate', GateOutcome::STATUS_FAILED, true, null, true, null)],
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: [],
            profile: VerificationGate::PROFILE_PHP_LARAVEL,
        );
    }

    public function test_passed_when_everything_green_and_no_honesty_flags(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationPassed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+a", ['x']),
        );

        $this->assertSame(CompletionSummary::STATUS_PASSED, $decision->status);
        $this->assertSame([], $decision->honestyFlags);
    }

    public function test_failed_when_scope_guard_failed(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopeFailed(),
            verificationResult: $this->verificationPassed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::patch("--- a/vendor/x.php\n+++ b/vendor/x.php\n@@\n-old\n+new\n", ['vendor/x.php']),
        );

        $this->assertSame(CompletionSummary::STATUS_FAILED, $decision->status);
    }

    public function test_failed_when_verification_failed(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationFailed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+a", ['x']),
        );

        $this->assertSame(CompletionSummary::STATUS_FAILED, $decision->status);
    }

    public function test_failed_when_diff_parse_invalid(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationPassed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::invalid(['no_unified_diff_detected']),
        );

        $this->assertSame(CompletionSummary::STATUS_FAILED, $decision->status);
    }

    public function test_failed_when_provider_call_returned_non_ok(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationPassed(),
            callResult: ProviderCallResult::fromStdout(
                runId: 'r', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
                exitStatus: 2, stdout: '', stderr: 'fail', durationMs: 0,
                errors: ['provider_exit_2'],
            ),
            diffResult: DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+a", ['x']),
        );

        $this->assertSame(CompletionSummary::STATUS_FAILED, $decision->status);
    }

    public function test_needs_review_when_verification_needs_review(): void
    {
        $verification = new VerificationGateResult(
            tests: [],
            gates: [new GateOutcome('verification_gate', GateOutcome::STATUS_NEEDS_REVIEW, true, null, true, null)],
            aggregateStatus: VerificationGateResult::STATUS_NEEDS_REVIEW,
            honestyFlags: ['test_skipped_no_reason'],
        );

        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $verification,
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+a", ['x']),
        );

        $this->assertSame(CompletionSummary::STATUS_NEEDS_REVIEW, $decision->status);
        $this->assertContains('test_skipped_no_reason', $decision->honestyFlags);
    }

    public function test_no_patch_needed_when_diff_parser_reports_so(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationPassed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::noPatchNeeded('already fixed upstream'),
        );

        $this->assertSame(CompletionSummary::STATUS_NO_PATCH_NEEDED, $decision->status);
        $this->assertSame([], $decision->honestyFlags);
    }

    public function test_blocked_when_diff_parser_reports_blocked(): void
    {
        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $this->verificationPassed(),
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::blocked('which adapter?'),
        );

        $this->assertSame(CompletionSummary::STATUS_BLOCKED, $decision->status);
    }

    public function test_passed_is_downgraded_to_needs_review_when_honesty_flag_present(): void
    {
        $verification = new VerificationGateResult(
            tests: [new TestRun('composer test', true, 0, 50, hash('sha256', 'ok'), null)],
            gates: [new GateOutcome('verification_gate', GateOutcome::STATUS_PASSED, true, null, true, null)],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: ['verification_command_timed_out'],
        );

        $decision = $this->gate()->decide(
            taskContract: $this->taskContractFixture(),
            scopeReceipt: $this->scopePassed(),
            verificationResult: $verification,
            callResult: $this->happyCall(),
            diffResult: DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+a", ['x']),
        );

        $this->assertSame(CompletionSummary::STATUS_NEEDS_REVIEW, $decision->status);
        $this->assertContains('verification_command_timed_out', $decision->honestyFlags);
    }
}
