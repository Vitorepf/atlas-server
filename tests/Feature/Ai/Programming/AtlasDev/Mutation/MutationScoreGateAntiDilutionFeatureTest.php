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
use App\Services\Ai\Programming\AtlasDev\Mutation\PerFileMutationStats;
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
 * E3 — Per-file anti-dilution end-to-end verdict propagation (VAL-E3-013).
 *
 * Drives synthetic multi-file mutation results through the
 * MutationTestingAdapter -> MutationTestingResult -> MutationScoreGate chain
 * and proves the anti-dilution check surfaces through the sanctioned verdict
 * channels:
 *
 *   - ADVISORY: the weak file below threshold appends the honesty flag, and
 *     the CompletionStateGate auto-downgrades PASSED -> needs_review (never
 *     green-with-flag, the passed-forbids-flags ctor invariant).
 *   - HARD: the weak file below threshold routes to STATUS_FAILED (never
 *     just downgrades in hard mode).
 *
 * The dilution scenario: a multi-file scope with aggregate MSI=80 (above
 * threshold 60) but one file at MSI=40 (below threshold). The gate MUST trip
 * on the weak file despite the high aggregate — it cannot be masked by the
 * strong files in the union.
 */
final class MutationScoreGateAntiDilutionFeatureTest extends TestCase
{
    // -- VAL-E3-013 advisory: weak file => flag + needs_review (never green) --

    public function test_val_e3_013_advisory_weak_file_downgrades_passed_to_needs_review(): void
    {
        $verdict = $this->evaluateAdvisory(
            aggregateMsi: 80.0,
            perFile: [
                new PerFileMutationStats('app/Strong.php', 100.0, 10, 10),
                new PerFileMutationStats('app/Weak.php', 40.0, 2, 5),
            ],
        );

        $this->assertTrue(
            $verdict->tripped,
            'VAL-E3-013 advisory: weak file trips despite aggregate MSI=80',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );
        $this->assertFalse($verdict->shouldFailGate, 'advisory: flag only');

        // End-to-end: the flag on a green gate downgrades PASSED to
        // needs_review (the mechanical floor: passed-forbids-flags).
        $verificationResult = $this->greenGateResult()->withHonestyFlags($verdict->honestyFlags);
        $decision = $this->decide($verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'VAL-E3-013 advisory: weak file downgrades to needs_review (never green)',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
        );
    }

    // -- VAL-E3-013 hard: weak file => STATUS_FAILED -------------------------

    public function test_val_e3_013_hard_weak_file_routes_to_status_failed(): void
    {
        $verdict = $this->evaluateHard(
            aggregateMsi: 80.0,
            perFile: [
                new PerFileMutationStats('app/Strong.php', 100.0, 10, 10),
                new PerFileMutationStats('app/Weak.php', 40.0, 2, 5),
            ],
        );

        $this->assertTrue($verdict->tripped, 'hard trips on the weak file');
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E3-013 hard: STATUS_FAILED (never just downgrades)',
        );

        // End-to-end: STATUS_FAILED gate => completion failed.
        $green = $this->greenGateResult();
        $verificationResult = new VerificationGateResult(
            tests: $green->tests,
            gates: $green->gates,
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: $verdict->honestyFlags,
            evidenceRefs: $green->evidenceRefs,
            profile: $green->profile,
        );

        $decision = $this->decide($verificationResult);
        $this->assertSame(
            CompletionSummary::STATUS_FAILED,
            $decision->status,
            'VAL-E3-013 hard: completion is failed',
        );
    }

    // -- VAL-E3-013: all files robust => passes cleanly ----------------------

    public function test_val_e3_013_all_files_robust_passes_cleanly(): void
    {
        $verdict = $this->evaluateAdvisory(
            aggregateMsi: 85.0,
            perFile: [
                new PerFileMutationStats('app/A.php', 80.0, 8, 10),
                new PerFileMutationStats('app/B.php', 90.0, 9, 10),
            ],
        );

        $this->assertFalse($verdict->tripped, 'all files robust => no trip');
        $this->assertSame([], $verdict->honestyFlags);

        // Green completion preserved (no flag appended).
        $decision = $this->decide($this->greenGateResult());
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'all files robust: green completion preserved',
        );
    }

    // -- VAL-E3-013: adapter -> result -> gate integration -------------------

    public function test_adapter_produces_per_file_stats_and_gate_uses_them(): void
    {
        // The FakeMutationCommandRunner provides per-file stats through the
        // MutationCommandOutcome, and the adapter passes them through to the
        // MutationTestingResult. The gate then checks them.
        $runner = new FakeMutationCommandRunner;
        $runner->queueOk(
            msi: 80.0,
            summaryPath: '/tmp/s.json',
            perFileStats: [
                new PerFileMutationStats('app/Strong.php', 100.0, 10, 10),
                new PerFileMutationStats('app/Weak.php', 40.0, 2, 5),
            ],
        );

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-anti-dilution',
            touchedFiles: [
                'tests/Unit/StrongTest.php',
                'tests/Unit/WeakTest.php',
                'app/Strong.php',
                'app/Weak.php',
            ],
        );

        // The adapter passed per-file stats through to the result.
        $this->assertNotNull($result->perFileStats, 'adapter carries per-file stats');
        $this->assertCount(2, $result->perFileStats);

        // The gate trips on the weak file.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );
        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'gate trips on the weak file from adapter per-file stats');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
        );
    }

    // -- Helpers --------------------------------------------------------------

    private function evaluateAdvisory(float $aggregateMsi, array $perFile)
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        return $gate->evaluate($this->completedResult($aggregateMsi, $perFile));
    }

    private function evaluateHard(float $aggregateMsi, array $perFile)
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        return $gate->evaluate($this->completedResult($aggregateMsi, $perFile));
    }

    private function completedResult(float $aggregateMsi, array $perFile): MutationTestingResult
    {
        return MutationTestingResult::completed(
            msi: $aggregateMsi,
            summaryPath: '/tmp/e3-anti-dilution-feature.json',
            scope: new MutationScope(
                testFiles: ['tests/Unit/MultiFileTest.php'],
                sourceFiles: array_map(
                    fn (PerFileMutationStats $s) => $s->filePath,
                    $perFile,
                ),
            ),
            perFileStats: $perFile,
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
                runId: 'run-e3-anti-dilution',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: "--- a/file\n+++ b/file\n@@\n+added\n",
                changedFiles: ['tests/Unit/MultiFileTest.php', 'app/Strong.php', 'app/Weak.php'],
            ),
        );
    }

    private function makeContract(): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e3-anti-dilution',
            taskId: 'task-e3-anti-dilution',
            specHash: 'spec-hash-e3-anti-dilution',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Strong.php', 'app/Weak.php'],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 3,
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
            taskContractHash: 'tch-e3-anti-dilution',
            noTestReason: null,
            intentText: 'add multi-file feature',
            intentVerbs: ['add'],
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-e3-anti-dilution',
            taskContractHash: 'tch-e3-anti-dilution',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['tests/Unit/MultiFileTest.php', 'app/Strong.php', 'app/Weak.php'],
                changedFilesCount: 3,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'tests/Unit/MultiFileTest.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'test'),
                    ),
                    new ScopeFileDiff(
                        path: 'app/Strong.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'strong'),
                    ),
                    new ScopeFileDiff(
                        path: 'app/Weak.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'weak'),
                    ),
                ],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Strong.php', 'app/Weak.php'],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 3,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }
}
