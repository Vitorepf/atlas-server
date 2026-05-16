<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use PHPUnit\Framework\TestCase;

final class FailureCapsuleBuilderTest extends TestCase
{
    private function builder(): FailureCapsuleBuilder
    {
        return new FailureCapsuleBuilder(new FailureSignatureHasher());
    }

    private function policy(int $maxAttempts = 2, bool $abortOnSameSignature = true): RepairPolicy
    {
        return new RepairPolicy(
            maxAttempts: $maxAttempts,
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: $abortOnSameSignature,
        );
    }

    public function test_initial_capsule_is_retry_when_budget_remains(): void
    {
        $capsule = $this->builder()->buildInitial(
            runId: 'r1',
            taskContractHash: 'tch',
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorRaw: "[2026-05-16T12:00:00Z] FooTest failed at /tmp/run-1/foo.php:10",
            fullErrorLogPath: '/tmp/x.log',
            failingTest: 'tests/FooTest::test_x',
            diffHash: 'sha:diff',
            changedFiles: ['app/Foo.php'],
            policy: $this->policy(maxAttempts: 2),
        );

        $this->assertSame(FailureCapsule::DECISION_RETRY, $capsule->decision);
        $this->assertNotEmpty($capsule->failureSignature);
        // Excerpt was normalized (timestamps/paths replaced).
        $this->assertStringNotContainsString('2026-05-16', $capsule->primaryErrorExcerpt);
        $this->assertStringNotContainsString('/tmp/', $capsule->primaryErrorExcerpt);
    }

    public function test_initial_capsule_is_stop_when_budget_is_zero(): void
    {
        $capsule = $this->builder()->buildInitial(
            runId: 'r1',
            taskContractHash: 'tch',
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorRaw: 'assertion failed',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: null,
            changedFiles: [],
            policy: $this->policy(maxAttempts: 0),
        );

        $this->assertSame(FailureCapsule::DECISION_STOP, $capsule->decision);
        $this->assertSame([], $capsule->escalationSignalDelta);
    }

    public function test_same_signature_twice_escalates_with_signal(): void
    {
        $builder = $this->builder();
        $policy = $this->policy(maxAttempts: 3);

        // Both excerpts wrap the same logical error in different volatile
        // wrappers (timestamps, paths, line numbers, run ids) — the hasher
        // must collapse them to the same signature.
        $first = $builder->buildInitial(
            runId: 'r1',
            taskContractHash: 'tch',
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorRaw: '[2026-05-16T12:00:00Z] AssertionError: expected 1 got 0 at /tmp/run-1/foo.php:5',
            fullErrorLogPath: null,
            failingTest: 'tests/FooTest::test_x',
            diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'],
            policy: $policy,
        );
        $this->assertSame(FailureCapsule::DECISION_RETRY, $first->decision);

        $secondOutcome = new RepairAttemptOutcome(
            status: RepairAttemptOutcome::STATUS_FAILED,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            // Same logical error, only volatile bits differ → same signature.
            primaryErrorExcerpt: '[2026-05-17T08:34:12Z] AssertionError: expected 1 got 0 at /tmp/run-9/foo.php:99',
            fullErrorLogPath: null,
            failingTest: 'tests/FooTest::test_x',
            diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'],
            diffSizeLines: 10,
        );

        $second = $builder->buildFromOutcome(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            outcome: $secondOutcome,
            previousCapsule: $first,
            policy: $policy,
            attemptsAllowed: 2,
        );

        $this->assertSame(FailureCapsule::DECISION_ESCALATE, $second->decision);
        $this->assertContains(FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE, $second->escalationSignalDelta);
    }

    public function test_diff_growth_escalates_even_when_signature_differs(): void
    {
        $builder = $this->builder();
        $policy = $this->policy(maxAttempts: 3);

        $first = $builder->buildInitial(
            runId: 'r1', taskContractHash: 'tch',
            gate: 'verification_gate', command: 'composer test',
            exitCode: 1, primaryErrorRaw: 'AssertionError: a',
            fullErrorLogPath: null, failingTest: null, diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'],
            policy: $policy,
        );

        $grewOutcome = new RepairAttemptOutcome(
            status: RepairAttemptOutcome::STATUS_FAILED,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'TypeError: completely different problem now',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: 'sha:b',
            // Grew from 1 -> 3 files. Diff growth should win.
            changedFiles: ['app/Foo.php', 'app/Bar.php', 'app/Baz.php'],
            diffSizeLines: 40,
        );

        $second = $builder->buildFromOutcome(
            runId: 'r1', taskContractHash: 'tch',
            attemptIndex: 1, outcome: $grewOutcome,
            previousCapsule: $first, policy: $policy, attemptsAllowed: 2,
        );

        $this->assertSame(FailureCapsule::DECISION_ESCALATE, $second->decision);
        $this->assertContains(FailureCapsuleBuilder::SIGNAL_DIFF_GROWTH, $second->escalationSignalDelta);
    }

    public function test_scope_violation_outcome_escalates(): void
    {
        $builder = $this->builder();
        $policy = $this->policy(maxAttempts: 3);

        $first = $builder->buildInitial(
            runId: 'r1', taskContractHash: 'tch',
            gate: 'verification_gate', command: 'composer test',
            exitCode: 1, primaryErrorRaw: 'err',
            fullErrorLogPath: null, failingTest: null, diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'], policy: $policy,
        );

        $scopeOutcome = new RepairAttemptOutcome(
            status: RepairAttemptOutcome::STATUS_SCOPE_VIOLATION,
            gate: 'scope_guard_light',
            command: null,
            exitCode: null,
            primaryErrorExcerpt: 'attempt touched config/secrets.php which is forbidden',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: 'sha:b',
            changedFiles: ['app/Foo.php', 'config/secrets.php'],
            diffSizeLines: 5,
        );

        $second = $builder->buildFromOutcome(
            runId: 'r1', taskContractHash: 'tch',
            attemptIndex: 1, outcome: $scopeOutcome,
            previousCapsule: $first, policy: $policy, attemptsAllowed: 2,
        );

        $this->assertSame(FailureCapsule::DECISION_ESCALATE, $second->decision);
        $this->assertContains(FailureCapsuleBuilder::SIGNAL_SCOPE_VIOLATION, $second->escalationSignalDelta);
    }

    public function test_max_attempts_translates_to_stop(): void
    {
        $builder = $this->builder();
        $policy = $this->policy(maxAttempts: 1);

        $first = $builder->buildInitial(
            runId: 'r1', taskContractHash: 'tch',
            gate: 'verification_gate', command: 'composer test',
            exitCode: 1, primaryErrorRaw: 'err',
            fullErrorLogPath: null, failingTest: null, diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'], policy: $policy,
        );

        $outcome = new RepairAttemptOutcome(
            status: RepairAttemptOutcome::STATUS_FAILED,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'still failing for an entirely different reason now',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: 'sha:b',
            changedFiles: ['app/Foo.php'],
            diffSizeLines: 10,
        );

        $second = $builder->buildFromOutcome(
            runId: 'r1', taskContractHash: 'tch',
            attemptIndex: 1, outcome: $outcome,
            previousCapsule: $first, policy: $policy, attemptsAllowed: 1,
        );

        $this->assertSame(FailureCapsule::DECISION_STOP, $second->decision);
        $this->assertSame([], $second->escalationSignalDelta);
    }

    public function test_oversize_excerpt_is_clamped_to_4kb(): void
    {
        $huge = str_repeat('A', 6000);
        $capsule = $this->builder()->buildInitial(
            runId: 'r1', taskContractHash: 'tch',
            gate: 'verification_gate', command: 'composer test',
            exitCode: 1, primaryErrorRaw: $huge,
            fullErrorLogPath: null, failingTest: null, diffHash: null,
            changedFiles: ['app/Foo.php'], policy: $this->policy(),
        );

        $this->assertLessThanOrEqual(4096, strlen($capsule->primaryErrorExcerpt));
        $this->assertStringContainsString('[truncated]', $capsule->primaryErrorExcerpt);
    }

    public function test_capsule_round_trips_through_canonical_array(): void
    {
        $capsule = $this->builder()->buildInitial(
            runId: 'r1', taskContractHash: 'tch',
            gate: 'verification_gate', command: 'composer test',
            exitCode: 1, primaryErrorRaw: 'AssertionError: a',
            fullErrorLogPath: '/tmp/x.log', failingTest: 'tests/Foo::a', diffHash: 'sha:a',
            changedFiles: ['app/Foo.php'], policy: $this->policy(),
        );

        $rebuilt = FailureCapsule::fromArray($capsule->toCanonicalArray());
        $this->assertSame($capsule->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($capsule->hash(), $rebuilt->hash());
    }
}
