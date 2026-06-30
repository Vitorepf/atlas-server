<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineResult;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Illuminate\Container\Container;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E5 hard-gate promotion (m2-e5-hard).
 *
 * Drives a REAL {@see PipelineRunExecutor} with `e5.mode=hard` against the
 * fake-provider harness and a scripted {@see RegressionBaselineRunner} (bound
 * via the container so no real test subprocess spawns), and proves the two
 * M2-E5 assertions:
 *
 *   - VAL-M2-012: a hard E5 trip on a passed-before/fails-after regression
 *     produces a `failed` completion (NOT the advisory `needs_review`), with
 *     the verification gate forced to STATUS_FAILED and the
 *     `regression_detected` honesty flag retained. The regressing test name
 *     is carried in the persisted receipt's test evidence.
 *   - VAL-M2-013: a hard E5 does NOT false-fail on a pre-existing failure
 *     (a test that was already failing pre-patch and still fails post-patch
 *     is NOT a regression). No `regression_detected` flag is appended and E5
 *     does not trip.
 *
 * The hard branch already exists in PipelineRunExecutor (precondition); this
 * class is the fixture proof the rollout guard (VAL-M2-028) requires BEFORE
 * the `e5.mode` config default flips to `hard`. The trip-fires and
 * does-not-false-fail methods are registered in
 * ElevationRolloutGuardTest::VERIFIED_FIXTURES.
 *
 * These tests set `e5.mode=hard` explicitly (via the config kernel) so they
 * prove the hard branch regardless of the shipped config default, and remain
 * green after the default is promoted. E1, E2 and E4 are set to `off` in
 * setUp so only E5 is active (the fixture uses the default task-contract
 * which carries empty intent fields and a non-PHP allowed file, so E1/E2/E4
 * would not trip anyway, but setting them off guarantees isolation).
 *
 * The allowed file is a `.txt` file so the M1 verification-gate floor adds no
 * PHP-specific commands (php -l / pint) and E4's shadow-diff extractor finds
 * no PHP symbols to probe. The regression is engineered entirely through the
 * scripted baseline runner (pre-patch pass/fail) and the FakeCommandRunner
 * (post-patch pass/fail), so no real subprocess executes.
 */
final class E5HardGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the E5 baseline capture + post-gate regression check
        // run (the fast path bypasses the provider and would short-circuit
        // the baseline capture which happens before the repair loop).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E5 hard: the elevation under test.
        config()->set('atlas_dev.elevations.e5.mode', 'hard');

        // Isolate E5 from E1/E2/E4 (all hard by default after their respective
        // promotions). The fixture uses the default task-contract (empty
        // intent fields) and a non-PHP allowed file, so E1/E2/E4 would not
        // trip, but setting them off guarantees only E5 can trip.
        config()->set('atlas_dev.elevations.e1.mode', 'off');
        config()->set('atlas_dev.elevations.e2.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e5-hard-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e5-hard-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-012: a hard E5 trip on a passed-before/fails-after regression
     * produces a `failed` completion (not `needs_review`), the verification
     * gate is forced to STATUS_FAILED, and the `regression_detected` honesty
     * flag is retained for auditability. The regressing test name is carried
     * in the persisted receipt's test evidence.
     *
     * The fixture: the validation command `composer test` PASSES in the
     * pre-patch baseline (exit 0 = passed-before) and FAILS post-patch
     * (exit 1 = fails-after). This is a regression (passed-before,
     * fails-after). E5 hard forces STATUS_FAILED.
     *
     * This is the trip-fires fixture registered in the rollout guard.
     */
    public function test_e5_hard_trip_produces_failed_on_passed_before_fails_after_regression(): void
    {
        $runId = 'dev-e5-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->setupWorkspaceWithFile('notes.txt', "line one\nold content\n");

        // Baseline runner: the validation command PASSED before the patch
        // (exit 0 => passed-before). This is the clean-tree baseline.
        $baselineRunner = new FakeRegressionBaselineRunner;
        $baselineRunner->queueExitCode(0); // passed-before

        // A benign diff on a non-PHP allowed file so the executor proceeds
        // through the provider call + verification gate. The diff content is
        // irrelevant to E5 (the regression is engineered via the scripted
        // baseline + post-patch command results).
        $diff = <<<'DIFF'
--- a/notes.txt
+++ b/notes.txt
@@ -1,2 +1,2 @@
 line one
-old content
+new content
DIFF;

        // Post-patch verification: the validation command FAILS (exit 1 =>
        // fails-after). Combined with the passed-before baseline, this is a
        // regression.
        $executor = $this->makeExecutor($storage, $diff, $baselineRunner, postPatchExitCode: 1);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['notes.txt'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'repair_policy' => [
                'max_attempts' => 0,
                'abort_on_same_signature_twice' => true,
                'requires_failed_gate_output' => true,
                'same_provider' => true,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M2-012: completion is `failed` (the firm block), NOT the
        // advisory `needs_review` soft flag.
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-012: E5 hard trip must produce a failed completion, not needs_review. '
            .'Got: '.$result->completionState,
        );

        // VAL-M2-012: the verification gate aggregate is forced to
        // STATUS_FAILED by the E5 hard rebuild (the sanctioned hard channel).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-012: E5 hard must force the verification gate to STATUS_FAILED.',
        );

        // VAL-M2-012: the `regression_detected` honesty flag is retained in
        // the rebuilt VerificationGateResult / persisted receipt for
        // auditability (the operator can see WHY it failed).
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $receipt->completion->honestyFlags,
            'VAL-M2-012: the regression_detected flag must be retained for auditability '
            .'when E5 hard forces STATUS_FAILED. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-012: the regressing test name is carried in the persisted
        // receipt's test evidence (the command that passed-before and
        // fails-after). This is the operator-visible "which test regressed"
        // evidence the assertion requires.
        $regressingTest = $this->findTest($receipt, 'composer test');
        $this->assertNotNull(
            $regressingTest,
            'VAL-M2-012: the regressing test (composer test) must be present in the receipt tests. '
            .'Tests: '.json_encode(array_map(fn (TestRun $t): string => $t->command, $receipt->tests), JSON_THROW_ON_ERROR),
        );
        $this->assertFalse(
            $regressingTest->ok,
            'VAL-M2-012: the regressing test must be recorded as failing (ok=false) post-patch.',
        );
    }

    /**
     * VAL-M2-013: a hard E5 does NOT false-fail on a pre-existing failure
     * (a test that was already failing pre-patch and still fails post-patch
     * is NOT a regression). No `regression_detected` flag is appended and E5
     * does not trip.
     *
     * The fixture: the validation command `composer test` FAILS in the
     * pre-patch baseline (exit 1 = failed-before, pre-existing) and STILL
     * FAILS post-patch (exit 1 = fails-after). This is a pre-existing
     * failure (failed-before + failed-after), NOT a regression. E5 hard does
     * not trip — no `regression_detected` flag is appended.
     *
     * The verification gate is FAILED (the pre-existing failure still fails
     * post-patch), so the completion is `failed` from the GATE — but NOT
     * from E5 (no `regression_detected` flag). The absence of the flag is
     * the proof that E5 correctly classified the pre-existing failure as a
     * non-regression and did not false-fail.
     *
     * This is the does-not-false-fail fixture registered in the rollout guard.
     */
    public function test_e5_hard_does_not_false_fail_on_pre_existing_failure(): void
    {
        $runId = 'dev-e5-hard-clear-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->setupWorkspaceWithFile('notes.txt', "line one\nold content\n");

        // Baseline runner: the validation command was ALREADY FAILING before
        // the patch (exit 1 => failed-before, pre-existing failure).
        $baselineRunner = new FakeRegressionBaselineRunner;
        $baselineRunner->queueExitCode(1); // failed-before (pre-existing)

        $diff = <<<'DIFF'
--- a/notes.txt
+++ b/notes.txt
@@ -1,2 +1,2 @@
 line one
-old content
+new content
DIFF;

        // Post-patch verification: the validation command STILL FAILS (exit 1
        // => fails-after, pre-existing failure persists). failed-before +
        // failed-after = pre-existing, NOT a regression.
        $executor = $this->makeExecutor($storage, $diff, $baselineRunner, postPatchExitCode: 1);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['notes.txt'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'repair_policy' => [
                'max_attempts' => 0,
                'abort_on_same_signature_twice' => true,
                'requires_failed_gate_output' => true,
                'same_provider' => true,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M2-013: E5 did NOT trip — no `regression_detected` flag is
        // appended. The pre-existing failure (failed-before + failed-after)
        // is NOT classified as a regression. This is the core false-fail
        // guard: E5 hard does not block a run for pre-existing breakage the
        // operator did not cause.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $receipt->completion->honestyFlags,
            'VAL-M2-013: no regression_detected flag on a pre-existing failure (not a regression). '
            .'E5 hard must not false-fail. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-013: the completion is `failed` from the GATE (the
        // pre-existing failure still fails the verification gate), NOT from
        // E5. The proof: E5 added no `regression_detected` flag (asserted
        // above). The gate's STATUS_FAILED is the pre-existing failure, not
        // an E5 hard trip. We assert the gate is FAILED (expected: the test
        // is genuinely failing) but E5 contributed no regression flag.
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-013: the gate is FAILED from the pre-existing failure (expected, not caused by E5).',
        );

        // Corollary: contrast with the trip fixture — the ONLY difference
        // between the trip and clear fixtures is whether the test passed
        // before (trip: exit 0) or was already failing before (clear: exit
        // 1). The regression_detected flag is present in the trip and absent
        // in the clear, proving E5's regression classification is correct
        // (passed-before/fails-after = regression; failed-before/fails-after
        // = pre-existing, not a regression).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-013: completion is failed from the gate (pre-existing failure), not from E5.',
        );
    }

    /**
     * VAL-M2-012/013 (isolated): assert the RegressionBaselineGate itself
     * fires on the regression fixture and clears on the pre-existing-failure
     * fixture under hard mode, so the hard-channel behavior is anchored to a
     * correct gate verdict (not a coincidence of the executor wiring).
     */
    public function test_e5_gate_verdict_matches_the_trip_and_clear_fixtures(): void
    {
        $gate = new RegressionBaselineGate(ElevationConfig::for('e5', ['mode' => 'hard']));

        // Trip fixture: a passed-before/fails-after regression.
        $tripBaseline = RegressionBaselineCache::capture(['composer test' => true], captureOrder: 0);
        $tripPostPatch = [
            new TestRun(
                command: 'composer test',
                ok: false,
                exitCode: 1,
                durationMs: 0,
                outputHash: hash('sha256', 'fail'),
                outputPath: null,
            ),
        ];
        $tripVerdict = $gate->evaluate(new RegressionBaselineResult($tripBaseline, $tripPostPatch, ['composer test']));
        $this->assertTrue($tripVerdict->tripped, 'The trip fixture (passed-before/fails-after) must trip the gate in hard mode.');
        $this->assertTrue($tripVerdict->shouldFailGate, 'Hard mode on a regression must route to STATUS_FAILED.');
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $tripVerdict->honestyFlags,
            'The trip fixture must carry the regression_detected flag.',
        );
        $this->assertContains('composer test', $tripVerdict->regressions, 'The trip fixture must name the regressing test.');

        // Clear fixture: a pre-existing failure (failed-before/fails-after).
        $clearBaseline = RegressionBaselineCache::capture(['composer test' => false], captureOrder: 0);
        $clearPostPatch = [
            new TestRun(
                command: 'composer test',
                ok: false,
                exitCode: 1,
                durationMs: 0,
                outputHash: hash('sha256', 'fail'),
                outputPath: null,
            ),
        ];
        $clearVerdict = $gate->evaluate(new RegressionBaselineResult($clearBaseline, $clearPostPatch, []));
        $this->assertFalse($clearVerdict->tripped, 'The clear fixture (pre-existing failure) must NOT trip the gate in hard mode.');
        $this->assertFalse($clearVerdict->shouldFailGate, 'Hard mode on a pre-existing failure must NOT route to STATUS_FAILED.');
        $this->assertSame([], $clearVerdict->honestyFlags, 'The clear fixture must carry no honesty flags.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: null,
            ),
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
            gitState: new GitState(
                headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0,
            ),
            rawIntent: 'Update notes.txt',
            normalizedIntent: 'Update notes.txt',
            userConstraints: [],
            intentClarityLevel: 'high',
            dirtyWorktreePolicy: 'preserve_pre_existing_changes',
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: 'write_allowed',
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: str_repeat('e', 64),
        );
    }

    private function makeExecutor(
        ReceiptStorage $storage,
        string $gatewayStdout,
        FakeRegressionBaselineRunner $baselineRunner,
        int $postPatchExitCode,
    ): PipelineRunExecutor {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $gatewayStdout));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: $postPatchExitCode,
            stdout: $postPatchExitCode === 0 ? 'ok' : 'fail',
            stderr: $postPatchExitCode === 0 ? '' : '1 test failed',
            durationMs: 10,
        ));

        $baselineService = new RegressionBaselineService($baselineRunner);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        // Bind the fake E5 baseline service so the executor captures a
        // scripted pre-patch baseline (no real test subprocess spawns).
        $container->instance('atlas_dev.e5.regression_baseline_service', $baselineService);

        return new PipelineRunExecutor($container, $storage);
    }

    /**
     * Seed the persisted run artifacts (compact SDD + mini spec + open brain
     * projection) so the executor can load them during the post-gate block.
     */
    private function seedRun(ReceiptStorage $storage, string $runId, string $taskKind, string $riskLevel): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => $taskKind, 'risk_level' => $riskLevel]);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpec = $this->miniSpecFixture(['compact_sdd_hash' => $compactPayload['compact_sdd_hash']]);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * Write a file into the workspace so the executor's diff application
     * produces the new content. No git init required (the executor applies
     * the diff to the workspace file directly).
     */
    private function setupWorkspaceWithFile(string $relativePath, string $content): void
    {
        $target = $this->tmpWorkspace.'/'.$relativePath;
        if (dirname($target) !== $this->tmpWorkspace) {
            mkdir(dirname($target), 0o755, true);
        }
        file_put_contents($target, $content);
    }

    private function loadReceipt(ReceiptStorage $storage, string $runId): VerificationReceipt
    {
        $payload = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($payload, 'verification_receipt.json must be persisted');

        return VerificationReceipt::fromArray($payload);
    }

    private function findTest(VerificationReceipt $receipt, string $command): ?TestRun
    {
        foreach ($receipt->tests as $test) {
            if ($test->command === $command) {
                return $test;
            }
        }

        return null;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Unit-test fake baseline runner. Queues scripted exit codes so the E5
 * baseline capture is exercised without spawning real test subprocesses.
 * Mirrors the anonymous-runner pattern in RegressionBaselineIntegrationTest
 * but as a named class for clarity.
 */
final class FakeRegressionBaselineRunner implements RegressionBaselineRunner
{
    /** @var list<int> */
    private array $exitCodes = [];

    /**
     * Queue a scripted exit code for the next baseline command run.
     */
    public function queueExitCode(int $exitCode): void
    {
        $this->exitCodes[] = $exitCode;
    }

    public function run(string $command, string $workspace): VerificationCommandResult
    {
        $exitCode = array_shift($this->exitCodes) ?? 0;

        return new VerificationCommandResult(
            command: $command,
            exitCode: $exitCode,
            stdout: $exitCode === 0 ? 'OK (baseline)' : 'FAIL (baseline)',
            stderr: $exitCode === 0 ? '' : 'pre-existing failure',
            durationMs: 10,
        );
    }
}
