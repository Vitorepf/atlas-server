<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScope;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Mutation\FakeMutationCommandRunner;

/**
 * E3 — Mutation-score gate end-to-end verdict propagation.
 *
 * VAL-E3-002, VAL-E3-003, VAL-E3-004, VAL-E3-007, VAL-E3-009, VAL-E3-010.
 *
 * Drives synthetic diffs through the fake-provider harness: a fake
 * MutationTestingAdapter reports a controlled real MSI, the MutationScoreGate
 * computes the verdict, and the verdict routes through exactly one of the
 * sanctioned channels (advisory honesty flag / hard STATUS_FAILED). The
 * CompletionStateGate then resolves the completion: advisory never yields a
 * green-with-flag (the passed-forbids-flags ctor invariant is the mechanical
 * floor, VAL-E3-009); hard never just downgrades (VAL-E3-003); off is
 * byte-identical (VAL-E3-010).
 *
 * This is the integration-level proof that the E3 verdict maps end-to-end
 * through the same post-gate block the executor uses for E1/E2, exercised
 * here by directly applying the verdict's channel mapping (the executor
 * logic) and resolving through the real CompletionStateGate.
 */
final class MutationScoreGateFlagTest extends TestCase
{
    // -- VAL-E3-002: advisory weak test => flag + needs_review (never green) --

    public function test_val_e3_002_advisory_weak_test_appends_flag_downgrades_passed(): void
    {
        $verdict = $this->evaluate(msi: 40.0, mode: 'advisory');

        $this->assertTrue($verdict->tripped, 'below-threshold trips in advisory');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-002: the honesty flag is appended',
        );
        $this->assertFalse(
            $verdict->shouldFailGate,
            'VAL-E3-002: advisory never forces STATUS_FAILED for the flag alone',
        );

        // The executor's advisory wiring: withHonestyFlags on a green gate.
        $verificationResult = $this->greenGateResult()->withHonestyFlags($verdict->honestyFlags);
        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'VAL-E3-002: advisory flag downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
            'VAL-E3-002: downgrade reason cites the honesty flags',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $decision->honestyFlags,
            'VAL-E3-002: flag carried into the completion decision',
        );
    }

    // -- VAL-E3-003: hard weak test => STATUS_FAILED gate (never just downgrade)

    public function test_val_e3_003_hard_weak_test_routes_to_status_failed(): void
    {
        $verdict = $this->evaluate(msi: 30.0, mode: 'hard');

        $this->assertTrue($verdict->tripped);
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E3-003: hard mode routes to STATUS_FAILED (sanctioned hard channel)',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-003: flag retained for auditability in hard mode',
        );

        // The executor's hard wiring: rebuild gate result with STATUS_FAILED.
        $green = $this->greenGateResult();
        $verificationResult = new VerificationGateResult(
            tests: $green->tests,
            gates: $green->gates,
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: $green->withHonestyFlags($verdict->honestyFlags)->honestyFlags,
            evidenceRefs: $green->evidenceRefs,
            profile: $green->profile,
        );

        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $verificationResult->aggregateStatus,
            'hard mode: gate forced to STATUS_FAILED',
        );

        $decision = $this->decide($verificationResult);
        $this->assertSame(
            CompletionSummary::STATUS_FAILED,
            $decision->status,
            'VAL-E3-003: completion is failed (never silently passed, never just needs_review)',
        );
    }

    // -- VAL-E3-004: robust test passes in both modes -----------------------

    public function test_val_e3_004_robust_test_passes_advisory_no_flag_no_block(): void
    {
        $verdict = $this->evaluate(msi: 80.0, mode: 'advisory');

        $this->assertFalse($verdict->tripped, 'VAL-E3-004: robust test does not trip');
        $this->assertSame([], $verdict->honestyFlags, 'no flag on a robust test');
        $this->assertFalse($verdict->shouldFailGate);

        // No flag appended on a pass — green completion preserved.
        $verificationResult = $this->greenGateResult();
        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E3-004: robust test stays passed in advisory',
        );
        $this->assertNotContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $decision->honestyFlags,
        );
    }

    public function test_val_e3_004_robust_test_passes_hard_no_flag_no_block(): void
    {
        $verdict = $this->evaluate(msi: 95.0, mode: 'hard');

        $this->assertFalse($verdict->tripped);
        $this->assertSame([], $verdict->honestyFlags);
        $this->assertFalse($verdict->shouldFailGate, 'hard does not fail a robust test');

        $verificationResult = $this->greenGateResult();
        $decision = $this->decide($verificationResult);
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E3-004: robust test stays passed in hard mode',
        );
    }

    // -- VAL-E3-007: verdict tracks the real reported MSI across the matrix --

    public function test_val_e3_007_verdict_tracks_real_msi_advisory_and_hard(): void
    {
        // The verdict is a pure function of the real reported MSI vs the
        // threshold (default 60.0), in both modes.
        $cases = [
            // [msi, advisoryTrips, hardTrips]
            [0.0, true, true],
            [59.99, true, true],
            [60.0, false, false], // boundary inclusive
            [60.01, false, false],
            [100.0, false, false],
        ];

        foreach ($cases as [$msi, $advisoryTrips, $hardTrips]) {
            $this->assertSame(
                $advisoryTrips,
                $this->evaluate(msi: (float) $msi, mode: 'advisory')->tripped,
                "VAL-E3-007 advisory: MSI={$msi} trips=".($advisoryTrips ? 'yes' : 'no'),
            );
            $this->assertSame(
                $hardTrips,
                $this->evaluate(msi: (float) $msi, mode: 'hard')->tripped,
                "VAL-E3-007 hard: MSI={$msi} trips=".($hardTrips ? 'yes' : 'no'),
            );
        }
    }

    // -- VAL-E3-009: advisory never falsely passes a weak test --------------

    public function test_val_e3_009_advisory_never_yields_green_with_flag(): void
    {
        // The mechanical floor: the CompletionDecision ctor forbids
        // (passed + flag), so once the E3 flag is appended on a green gate,
        // no advisory path can yield a green-with-flag completion.
        $verdict = $this->evaluate(msi: 25.0, mode: 'advisory');

        $this->assertTrue($verdict->tripped);
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );

        $verificationResult = $this->greenGateResult()->withHonestyFlags($verdict->honestyFlags);
        $decision = $this->decide($verificationResult);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E3-009: no green-with-flag in advisory',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'VAL-E3-009: advisory downgrades to needs_review',
        );
    }

    public function test_val_e3_009_completion_decision_passed_forbids_flag_invariant(): void
    {
        // The ctor invariant is the mechanical floor that no advisory path
        // can bypass: a passed CompletionDecision cannot carry the flag.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passed forbids honesty_flags');

        new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD],
            residualRisks: [],
        );
    }

    // -- VAL-E3-010: off => no flag, no fail, byte-identical -----------------

    public function test_val_e3_010_off_mode_weak_test_is_byte_identical_no_op(): void
    {
        // With e3.mode=off, the executor never invokes the adapter, so no
        // MutationTestingResult is produced and no flag is appended. The
        // green gate produces a passed completion (byte-identical to pre-E3).
        $verificationResult = $this->greenGateResult();
        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E3-010: off => byte-identical to pre-E3 (passed preserved)',
        );
        $this->assertNotContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $decision->honestyFlags,
            'VAL-E3-010: no E3 flag in off mode',
        );

        // And the gate itself, if handed a (hypothetical) result in off mode,
        // is a documented no-op: never trips, never fails.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($this->completedResult(msi: 10.0));
        $this->assertFalse($verdict->tripped, 'VAL-E3-010: off never trips');
        $this->assertFalse($verdict->shouldFailGate, 'off never fails');
        $this->assertTrue($verdict->isNoOp, 'off is a documented no-op');
    }

    public function test_val_e3_010_off_mode_skipped_result_is_also_no_op(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate(
            MutationTestingResult::skipped('e3.mode=off: byte-identical'),
        );
        $this->assertTrue($verdict->isNoOp);
        $this->assertFalse($verdict->tripped);
    }

    // -- VAL-E3-008 integration: skipped result => no-op (no false fail) ----

    public function test_val_e3_008_skipped_result_is_no_op_completion_preserved(): void
    {
        // A patch touching only non-test source => adapter skips => gate no-op.
        // The green completion is preserved with no E3 flag.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate(
            MutationTestingResult::skipped('e3: no added/modified test files'),
        );

        $this->assertFalse($verdict->tripped, 'VAL-E3-008: skipped never trips');
        $this->assertFalse($verdict->shouldFailGate, 'VAL-E3-008: never a false fail');
        $this->assertSame([], $verdict->honestyFlags, 'VAL-E3-008: no flag on a skip');

        // No flag => green completion preserved.
        $decision = $this->decide($this->greenGateResult());
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E3-008: source-only patch not blocked by E3',
        );
    }

    // -- Honest ceiling: a failed infection run does not silently green ----

    public function test_advisory_failed_infection_run_appends_flag_never_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate(
            MutationTestingResult::failed('e3: infection failed (exit 1)'),
        );

        $this->assertTrue($verdict->tripped, 'a failed run does not silently pass');
        $this->assertFalse($verdict->shouldFailGate, 'advisory never STATUS_FAILED');
        $this->assertNotEmpty($verdict->honestyFlags, 'advisory surfaces via a flag');
        $this->assertContains(
            MutationScoreGate::FLAG_MUTATION_RUN_FAILED,
            $verdict->honestyFlags,
            'the unevaluable-MSI flag is distinct from the below-threshold flag',
        );

        $decision = $this->decide($this->greenGateResult()->withHonestyFlags($verdict->honestyFlags));
        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'failed infection never silently greens in advisory',
        );
    }

    public function test_hard_failed_infection_run_fail_closes_to_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate(
            MutationTestingResult::failed('e3: infection failed (exit 1)'),
        );

        $this->assertTrue($verdict->tripped);
        $this->assertTrue(
            $verdict->shouldFailGate,
            'hard fail-closes over an unevaluable MSI (honest ceiling)',
        );
    }

    // -- Adapter + gate integration: the gate reads the adapter's real MSI --

    public function test_gate_reads_real_msi_reported_by_the_adapter(): void
    {
        // VAL-E3-007 integration: the gate consumes the adapter's structured
        // result, which carries the REAL infection-reported MSI parsed from
        // the summary JSON. A fake runner reports a controlled MSI; the gate
        // verdict tracks it.
        $runner = new FakeMutationCommandRunner;
        $runner->queueOk(msi: 50.0, summaryPath: '/tmp/s.json');
        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-integ',
            touchedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
        );

        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'MSI=50 < threshold=60 trips');
        $this->assertSame(50.0, $verdict->msi, 'verdict echoes the real reported MSI');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );
    }

    public function test_gate_off_mode_does_not_invoke_adapter_via_executor_skip(): void
    {
        // VAL-E3-010: in off mode the EXECUTOR skips the adapter invocation
        // entirely (the gate never sees a result). We assert the gate's
        // contract: when the executor DID skip (no result produced), the
        // adapter was never called. This is the byte-identical guarantee.
        $runner = new FakeMutationCommandRunner;
        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            repoRoot: '/repo',
        );

        // The executor guards with `if (! $e3Config->isOff())` BEFORE calling
        // $adapter->run(). Here we assert the adapter's own off-mode skip
        // (defence in depth): infection is not invoked.
        $result = $adapter->run(
            runId: 'run-e3-off-integ',
            touchedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
        );

        $this->assertTrue($result->skipped, 'off => adapter skipped');
        $this->assertSame([], $runner->calls, 'infection NOT invoked in off mode');
    }

    // -- Helpers --------------------------------------------------------------

    private function evaluate(float $msi, string $mode)
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => $mode]),
            threshold: 60.0,
        );

        return $gate->evaluate($this->completedResult(msi: $msi));
    }

    private function completedResult(float $msi): MutationTestingResult
    {
        return MutationTestingResult::completed(
            msi: $msi,
            summaryPath: '/tmp/e3-feature-summary.json',
            scope: new MutationScope(
                testFiles: ['tests/Unit/CalculatorTest.php'],
                sourceFiles: ['app/Calculator.php'],
            ),
        );
    }

    private function greenGateResult(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );
    }

    private function decide(VerificationGateResult $verificationResult): CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $this->makeContract(),
            scopeReceipt: $this->buildScopeReceipt(),
            verificationResult: $verificationResult,
            callResult: ProviderCallResult::fromStdout(
                runId: 'run-e3-flag-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: "--- a/file\n+++ b/file\n@@\n+added\n",
                changedFiles: ['tests/Unit/CalculatorTest.php', 'app/Calculator.php'],
            ),
        );
    }

    private function makeContract(): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e3-flag-test',
            taskId: 'task-e3-flag',
            specHash: 'spec-hash-e3-flag',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Calculator.php'],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 1,
            validationCommands: ['composer test'],
            evidenceRequired: ['verification_receipt'],
            repairPolicy: new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            escalationOn: [],
            providerLock: new ProviderLock(
                provider: 'hermes_cli',
                modelFamily: 'minimax-m3',
                fallbackAllowed: false,
            ),
            taskContractHash: 'tch-e3-flag',
            noTestReason: null,
            intentText: 'add a calculator',
            intentVerbs: ['add'],
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-e3-flag-test',
            taskContractHash: 'tch-e3-flag',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['tests/Unit/CalculatorTest.php', 'app/Calculator.php'],
                changedFilesCount: 2,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'tests/Unit/CalculatorTest.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'test'),
                    ),
                    new ScopeFileDiff(
                        path: 'app/Calculator.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'src'),
                    ),
                ],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Calculator.php'],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 1,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }
}
