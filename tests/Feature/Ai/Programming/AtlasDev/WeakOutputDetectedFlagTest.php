<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputDetector;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
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
use Tests\TestCase;

/**
 * W1 — weak_output_detected honesty flag end-to-end propagation.
 *
 * The DevWeakOutputDetector already feeds the M2 repair loop on a FAILED
 * gate; W1 covers the opposite corner — weak output that PASSES verification.
 * inspectAppliedDiff() computes the signal from the ADDED lines of the final
 * diff, the honesty flag is appended via withHonestyFlags(), and the
 * CompletionStateGate auto-downgrades PASSED -> needs_review (the
 * passed-forbids-flags invariant guarantees no green-with-flag).
 *
 * Mirrors the E2 IntentNotTestedFlagTest harness: this is the
 * integration-level proof that the advisory channel works end-to-end and
 * that the hard channel's gate rebuild preserves the flag.
 */
final class WeakOutputDetectedFlagTest extends TestCase
{
    private const PLACEHOLDER_DIFF = "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1,2 +1,3 @@\n context\n+// TODO: implement this properly\n+return true;\n";

    private const CLEAN_DIFF = "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@ -1,2 +1,3 @@\n context\n+return \$this->computeReal();\n";

    // -- Advisory: placeholder in added lines downgrades passed --------------

    public function test_placeholder_in_added_lines_downgrades_passed_to_needs_review(): void
    {
        $inspection = (new DevWeakOutputDetector)->inspectAppliedDiff(self::PLACEHOLDER_DIFF);

        $this->assertTrue($inspection['weak'], 'fixture: probe must fire on a TODO added line');

        // Append the flag on a green gate (what PipelineRunExecutor's W1
        // post-gate block does in advisory mode).
        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            DevWeakOutputDetector::FLAG_WEAK_OUTPUT_DETECTED,
        ]);

        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'W1: advisory weak_output_detected flag downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
            'W1: downgrade reason must cite the honesty flags',
        );
        $this->assertContains(
            DevWeakOutputDetector::FLAG_WEAK_OUTPUT_DETECTED,
            $decision->honestyFlags,
            'W1: the flag must survive into the completion decision',
        );
    }

    // -- Clear: clean added lines keep passed reachable ----------------------

    public function test_clean_added_lines_do_not_trip_and_passed_is_reachable(): void
    {
        $inspection = (new DevWeakOutputDetector)->inspectAppliedDiff(self::CLEAN_DIFF);

        $this->assertFalse(
            $inspection['weak'],
            'W1: probe must NOT fire on real implementation added lines',
        );

        $decision = $this->decide($this->greenGateResult());

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'W1: a green gate with no flag may report passed (no false-trip)',
        );
    }

    // -- Hard: gate rebuild forces failed while preserving the flag ----------

    public function test_hard_channel_rebuild_forces_failed_and_preserves_flag(): void
    {
        // What PipelineRunExecutor's W1 post-gate block does in hard mode:
        // rebuild the gate result at STATUS_FAILED with the flag retained.
        $green = $this->greenGateResult();
        $rebuilt = new VerificationGateResult(
            tests: $green->tests,
            gates: $green->gates,
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: $green->withHonestyFlags([
                DevWeakOutputDetector::FLAG_WEAK_OUTPUT_DETECTED,
            ])->honestyFlags,
        );

        $decision = $this->decide($rebuilt);

        $this->assertSame(
            CompletionSummary::STATUS_FAILED,
            $decision->status,
            'W1 hard: rebuilt STATUS_FAILED gate must resolve completion to failed',
        );
        $this->assertContains(
            DevWeakOutputDetector::FLAG_WEAK_OUTPUT_DETECTED,
            $decision->honestyFlags,
            'W1 hard: the flag is retained for auditability',
        );
    }

    // -- Helpers (mirrors IntentNotTestedFlagTest) ----------------------------

    private function greenGateResult(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );
    }

    private function decide(VerificationGateResult $verificationResult): \App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $this->makeContract(),
            scopeReceipt: $this->buildScopeReceipt(),
            verificationResult: $verificationResult,
            callResult: ProviderCallResult::fromStdout(
                runId: 'run-w1-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: self::PLACEHOLDER_DIFF,
                changedFiles: ['app/Foo.php'],
            ),
        );
    }

    private function makeContract(): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-w1-test',
            taskId: 'task-w1',
            specHash: 'spec-hash-w1',
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
            taskContractHash: 'tch-w1',
            noTestReason: null,
            intentText: 'implemente o cálculo real',
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-w1-test',
            taskContractHash: 'tch-w1',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['app/Foo.php'],
                changedFilesCount: 1,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'app/Foo.php',
                        added: 2,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'foo'),
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
