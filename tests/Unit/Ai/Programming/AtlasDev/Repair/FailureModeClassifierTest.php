<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureModeClassifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use PHPUnit\Framework\TestCase;

final class FailureModeClassifierTest extends TestCase
{
    public function test_scope_violation_signal_classifies_wrong_scope(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'verification_gate');

        $mode = $classifier->classify(
            $capsule,
            CompletionSummary::STATUS_FAILED,
            attemptCount: 1,
            escalationSignals: [FailureCapsuleBuilder::SIGNAL_SCOPE_VIOLATION],
        );

        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE, $mode);
    }

    public function test_scope_guard_gate_classifies_wrong_scope_even_without_signal(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'scope_guard_light');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 1),
        );
    }

    public function test_same_signature_twice_classifies_bad_repair(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'verification_gate');

        $mode = $classifier->classify(
            $capsule,
            CompletionSummary::STATUS_FAILED,
            attemptCount: 2,
            escalationSignals: [FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE],
        );
        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR, $mode);
    }

    public function test_multi_attempt_failure_without_signals_still_bad_repair(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'verification_gate');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 3),
        );
    }

    public function test_verification_gate_classifies_missed_test(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'verification_gate', failingTest: 'tests/FooTest::test_x');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 1),
        );
    }

    public function test_prompt_gate_classifies_prompt_projection_error(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'prompt_quality_gate');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_PROMPT_PROJECTION_ERROR,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 1),
        );
    }

    public function test_context_gate_classifies_context_error(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'context_budget_gate');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_CONTEXT_ERROR,
            $classifier->classify($capsule, CompletionSummary::STATUS_BLOCKED, attemptCount: 0),
        );
    }

    public function test_unknown_gate_falls_back_to_other(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(gate: 'mystery_gate', primaryError: 'whatever');

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_OTHER,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 1),
        );
    }

    public function test_single_temp_file_change_classifies_wrong_file(): void
    {
        $classifier = new FailureModeClassifier();
        $capsule = $this->capsule(
            gate: 'mystery_gate',
            changedFiles: ['app/Foo.php.bak'],
        );

        $this->assertSame(
            FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_FILE,
            $classifier->classify($capsule, CompletionSummary::STATUS_FAILED, attemptCount: 1),
        );
    }

    private function capsule(
        string $gate,
        ?string $failingTest = null,
        array $changedFiles = ['app/Foo.php'],
        string $primaryError = 'AssertionError: expected 1 got 0',
    ): FailureCapsule {
        return FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 0,
            gate: $gate,
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: $primaryError,
            fullErrorLogPath: null,
            failingTest: $failingTest,
            diffHash: null,
            changedFiles: $changedFiles,
            decision: FailureCapsule::DECISION_RETRY,
        );
    }
}
