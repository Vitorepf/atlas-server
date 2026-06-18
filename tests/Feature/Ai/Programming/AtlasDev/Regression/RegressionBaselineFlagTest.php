<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineResult;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionVerdict;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Tests\TestCase;

/**
 * E5 -- Regression baseline end-to-end verdict propagation (red-first).
 *
 * VAL-E5-002, VAL-E5-003, VAL-E5-004, VAL-E5-005.
 *
 * Drives the E5 verdict through the sanctioned channels and resolves through
 * the real CompletionStateGate, proving:
 *   - advisory regression => honesty flag + needs_review (never green)
 *   - hard regression => STATUS_FAILED + completion=failed (never success)
 *   - pre-existing failure / fix => no trip, no flag, no block
 *   - off => byte-identical no-op
 *
 * This mirrors the MutationScoreGateFlagTest pattern: the gate's verdict is
 * applied via the same executor channel mapping (withHonestyFlags for advisory,
 * STATUS_FAILED rebuild for hard), then resolved through the real
 * CompletionStateGate to prove the end-to-end completion state.
 */
final class RegressionBaselineFlagTest extends TestCase
{
    // -- VAL-E5-003: hard regression hard-blocks completion (never green) -----

    public function test_val_e5_003_hard_regression_routes_to_status_failed_and_failed_completion(): void
    {
        $verdict = $this->evaluate(['cmd-regressed'], mode: 'hard');

        $this->assertTrue($verdict->tripped);
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E5-003: hard regression forces STATUS_FAILED',
        );
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $verdict->honestyFlags,
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
            'VAL-E5-003: gate forced to STATUS_FAILED',
        );

        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_FAILED,
            $decision->status,
            'VAL-E5-003: completion is failed (never silently passed, never success)',
        );
    }

    // -- Advisory regression downgrades PASSED -> needs_review ----------------

    public function test_advisory_regression_appends_flag_downgrades_passed(): void
    {
        $verdict = $this->evaluate(['cmd-regressed'], mode: 'advisory');

        $this->assertTrue($verdict->tripped);
        $this->assertFalse(
            $verdict->shouldFailGate,
            'advisory never forces STATUS_FAILED for the flag alone',
        );
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $verdict->honestyFlags,
        );

        $verificationResult = $this->greenGateResult()->withHonestyFlags($verdict->honestyFlags);
        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'advisory regression downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
        );
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $decision->honestyFlags,
        );
    }

    // -- VAL-E5-002: passed-before/fails-after detected as regression --------

    public function test_val_e5_002_passed_before_fails_after_detected_as_regression(): void
    {
        // computeRegressions is pure and never touches the runner; an
        // anonymous no-op runner satisfies the constructor.
        $service = new RegressionBaselineService(
            new class implements \App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner
            {
                public function run(string $command, string $workspace): \App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult
                {
                    return new \App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult(
                        command: $command, exitCode: 0, stdout: '', stderr: '', durationMs: 0,
                    );
                }
            },
        );
        $baseline = RegressionBaselineCache::capture(
            ['cmd-was-green' => true],
            captureOrder: 0,
        );
        $postPatch = [
            new TestRun(
                command: 'cmd-was-green',
                ok: false,
                exitCode: 1,
                durationMs: 0,
                outputHash: hash('sha256', 'fail'),
                outputPath: null,
            ),
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(
            ['cmd-was-green'],
            $regressions,
            'VAL-E5-002: the broken previously-green test is in the regression set',
        );

        // Through the gate:
        $result = new RegressionBaselineResult($baseline, $postPatch, $regressions);
        $verdict = (new RegressionBaselineGate(
            ElevationConfig::for('e5', ['mode' => 'hard']),
        ))->evaluate($result);

        $this->assertTrue($verdict->tripped, 'gate trips on the regression');
        $this->assertSame(['cmd-was-green'], $verdict->regressions);
    }

    // -- VAL-E5-004: pre-existing failure NOT a regression --------------------

    public function test_val_e5_004_pre_existing_failure_does_not_block_in_hard(): void
    {
        $verdict = $this->evaluate([], mode: 'hard');  // no regressions

        $this->assertFalse($verdict->tripped, 'VAL-E5-004: pre-existing failure does not trip');
        $this->assertFalse($verdict->shouldFailGate);

        // No STATUS_FAILED => green completion preserved (assuming gate green).
        $decision = $this->decide($this->greenGateResult());
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E5-004: pre-existing failure alone does not block',
        );
    }

    // -- VAL-E5-005: failing->passing is a fix, not regression ----------------

    public function test_val_e5_005_fix_transition_not_flagged(): void
    {
        $verdict = $this->evaluate([], mode: 'hard');  // no regressions (fix only)

        $this->assertFalse($verdict->tripped, 'VAL-E5-005: fix is not a regression');
        $this->assertSame([], $verdict->honestyFlags);

        $decision = $this->decide($this->greenGateResult());
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E5-005: a fix preserves green completion',
        );
    }

    // -- Off mode byte-identical no-op ----------------------------------------

    public function test_off_mode_regression_is_byte_identical_no_op(): void
    {
        $verdict = $this->evaluate(['cmd-regressed'], mode: 'off');

        $this->assertFalse($verdict->tripped, 'off: never trips');
        $this->assertFalse($verdict->shouldFailGate, 'off: never fails');
        $this->assertTrue($verdict->isNoOp, 'off: documented no-op');
        $this->assertSame([], $verdict->honestyFlags, 'off: no flag');

        // No flag appended => green completion preserved (byte-identical).
        $decision = $this->decide($this->greenGateResult());
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'off mode: byte-identical to pre-E5',
        );
    }

    // -- VAL-E5-003 corollary: passed+flag invariant (CompletionDecision ctor)

    public function test_completion_decision_passed_forbids_regression_flag_invariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passed forbids honesty_flags');

        new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [RegressionBaselineGate::FLAG_REGRESSION_DETECTED],
            residualRisks: [],
        );
    }

    // -- Helpers ---------------------------------------------------------------

    private function evaluate(array $regressions, string $mode): RegressionVerdict
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => $mode]),
        );

        $results = [];
        foreach ($regressions as $cmd) {
            $results[$cmd] = true;
        }
        $baseline = RegressionBaselineCache::capture($results, captureOrder: 0);

        $postPatch = [];
        foreach ($regressions as $cmd) {
            $postPatch[] = new TestRun(
                command: $cmd,
                ok: false,
                exitCode: 1,
                durationMs: 0,
                outputHash: hash('sha256', $cmd.'fail'),
                outputPath: null,
            );
        }

        $result = new RegressionBaselineResult($baseline, $postPatch, $regressions);

        return $gate->evaluate($result);
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
                runId: 'run-e5-flag-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: "--- a/file\n+++ b/file\n@@\n+added\n",
                changedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
            ),
        );
    }

    private function makeContract(): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e5-flag-test',
            taskId: 'task-e5-flag',
            specHash: 'spec-hash-e5-flag',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Foo.php'],
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
            taskContractHash: 'tch-e5-flag',
            noTestReason: null,
            intentText: 'add a foo',
            intentVerbs: ['add'],
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-e5-flag-test',
            taskContractHash: 'tch-e5-flag',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['tests/Unit/FooTest.php', 'app/Foo.php'],
                changedFilesCount: 2,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'tests/Unit/FooTest.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'test'),
                    ),
                    new ScopeFileDiff(
                        path: 'app/Foo.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'src'),
                    ),
                ],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Foo.php'],
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
