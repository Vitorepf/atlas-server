<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarnessResult;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffResult;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E4 hard-gate promotion (m2-e4-hard).
 *
 * Drives a REAL {@see PipelineRunExecutor} with `e4.mode=hard` against the
 * fake-provider harness and a scripted {@see ShadowDiffHarness} (bound via
 * the container so no real PHP subprocess spawns), and proves the three
 * M2-E4 assertions:
 *
 *   - VAL-M2-009: a hard E4 trip on a pure-function divergence (the
 *     shadow-diff observes old != new on probed inputs) DESPITE a green
 *     verification gate produces a `failed` completion (NOT the advisory
 *     `needs_review`), with the verification gate forced to STATUS_FAILED
 *     and the `shadow_diff_regression` honesty flag retained.
 *   - VAL-M2-010: a hard E4 does NOT false-fail on a behavior-preserving
 *     pure refactor (the shadow-diff observes agreement, diverged=false).
 *     No `shadow_diff_regression` flag is appended and the completion is
 *     not `failed` due to E4.
 *
 * The hard branch already exists in PipelineRunExecutor (precondition); this
 * class is the fixture proof the rollout guard (VAL-M2-028) requires BEFORE
 * the `e4.mode` config default flips to `hard`. The trip-fires and
 * does-not-false-fail methods are registered in
 * ElevationRolloutGuardTest::VERIFIED_FIXTURES.
 *
 * These tests set `e4.mode=hard` explicitly (via the config kernel) so they
 * prove the hard branch regardless of the shipped config default, and remain
 * green after the default is promoted. E1 and E2 are set to `off` in setUp
 * so only E4 is active (the fixture uses the default task-contract which
 * carries empty intent fields, so E1/E2 would not trip anyway, but setting
 * them off guarantees isolation).
 *
 * The workspace is a real git repo so the ShadowDiffService can read the
 * OLD function via `git show HEAD:<path>` and the NEW function from the
 * workspace file. A fake harness scripts the old/new outputs so no real
 * PHP subprocess executes.
 */
final class E4HardGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the E4 post-gate shadow-diff runs on the workspace diff
        // (the fast path bypasses the provider and would short-circuit E4).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E4 hard: the elevation under test.
        config()->set('atlas_dev.elevations.e4.mode', 'hard');

        // Isolate E4 from E1/E2 (both hard by default after m2-e1-hard /
        // m2-e2-hard). The fixture uses the default task-contract (empty
        // intent fields), so E1/E2 would not trip, but setting them off
        // guarantees only E4 can trip in these fixtures.
        config()->set('atlas_dev.elevations.e1.mode', 'off');
        config()->set('atlas_dev.elevations.e2.mode', 'off');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e4-hard-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e4-hard-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-009: a hard E4 trip on a pure-function divergence (despite a
     * green verification gate) produces a `failed` completion (not
     * `needs_review`), the verification gate is forced to STATUS_FAILED, and
     * the `shadow_diff_regression` honesty flag is retained for auditability.
     *
     * The fixture: a pure function `add($x, $y)` changes from `$x + $y` to
     * `$x - $y` (a regression). The unit gate is GREEN (the test command
     * exits 0); the shadow-diff harness scripts diverging outputs so the
     * gate observes divergence. E4 hard forces STATUS_FAILED.
     *
     * This is the trip-fires fixture registered in the rollout guard.
     */
    public function test_e4_hard_trip_produces_failed_on_pure_function_divergence_despite_green_tests(): void
    {
        $runId = 'dev-e4-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        // Set up a git workspace: commit the OLD pure function as the HEAD
        // baseline. The executor's diff application will produce the NEW
        // (diverging) version in the workspace file.
        $this->setupGitWorkspaceWithPureFunction(
            oldContent: "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
        );

        // Script the shadow-diff harness to return DIVERGING old/new outputs
        // (add vs subtract over the probe inputs).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0', '3', '7'], ['0', '-1', '-1']);

        // A diff string that exactly matches the OLD file content so the
        // executor's patch application produces the NEW version in the
        // workspace. The shadow-diff service then reads the NEW version from
        // the workspace and the OLD from git HEAD.
        $diff = <<<'DIFF'
--- a/app/Math.php
+++ b/app/Math.php
@@ -1,2 +1,2 @@
 <?php
-function add(int $x, int $y): int { return $x + $y; }
+function add(int $x, int $y): int { return $x - $y; }
DIFF;

        $executor = $this->makeExecutor($storage, $diff, $harness);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Math.php'],
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

        // VAL-M2-009: completion is `failed` (the firm block), NOT the
        // advisory `needs_review` soft flag.
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-009: E4 hard trip must produce a failed completion, not needs_review. '
            .'Got: '.$result->completionState,
        );

        // VAL-M2-009: the verification gate aggregate is forced to
        // STATUS_FAILED by the E4 hard rebuild (the sanctioned hard channel).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-009: E4 hard must force the verification gate to STATUS_FAILED.',
        );

        // VAL-M2-009: the `shadow_diff_regression` honesty flag is retained
        // in the rebuilt VerificationGateResult / persisted receipt for
        // auditability (the operator can see WHY it failed).
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $receipt->completion->honestyFlags,
            'VAL-M2-009: the shadow_diff_regression flag must be retained for auditability '
            .'when E4 hard forces STATUS_FAILED. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-009: the verification gate was GREEN before E4 forced
        // STATUS_FAILED (proving the divergence was caught DESPITE green
        // tests). The harness was invoked (pure modified symbol).
        $this->assertSame(1, $harness->callCount, 'VAL-M2-009: harness invoked for the pure modified symbol');
    }

    /**
     * VAL-M2-010: a hard E4 does NOT false-fail on a behavior-preserving
     * pure refactor. The shadow-diff observes agreement (identical outputs
     * across all probed inputs, diverged=false): E4 does not trip, no
     * `shadow_diff_regression` flag is appended, and the completion is not
     * `failed` due to E4.
     *
     * The fixture: a pure function `add($x, $y)` changes from `$x + $y` to
     * `$y + $x` (commutative — behavior-preserving for addition). The
     * shadow-diff harness scripts AGREEING outputs. E4 hard does not trip.
     *
     * This is the does-not-false-fail fixture registered in the rollout guard.
     */
    public function test_e4_hard_does_not_false_fail_on_behavior_preserving_pure_refactor(): void
    {
        $runId = 'dev-e4-hard-clear-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        // Set up a git workspace: commit the OLD pure function as the HEAD
        // baseline. The executor's diff application will produce the NEW
        // (behavior-preserving) version in the workspace file.
        $this->setupGitWorkspaceWithPureFunction(
            oldContent: "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
        );

        // Script the shadow-diff harness to return AGREEING old/new outputs
        // (behavior-preserving refactor — identical outputs).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0', '3', '7'], ['0', '3', '7']);

        $diff = <<<'DIFF'
--- a/app/Math.php
+++ b/app/Math.php
@@ -1,2 +1,2 @@
 <?php
-function add(int $x, int $y): int { return $x + $y; }
+function add(int $x, int $y): int { return $y + $x; }
DIFF;

        $executor = $this->makeExecutor($storage, $diff, $harness);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Math.php'],
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

        // VAL-M2-010: E4 hard did NOT force STATUS_FAILED — the gate stays
        // STATUS_PASSED (the refactor is behavior-preserving). This is the
        // core false-fail guard: a legitimate green run is not blocked by E4.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-M2-010: E4 hard must not force STATUS_FAILED on a behavior-preserving pure refactor.',
        );

        // VAL-M2-010: no `shadow_diff_regression` flag is appended.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $receipt->completion->honestyFlags,
            'VAL-M2-010: no shadow_diff_regression flag on a behavior-preserving pure refactor. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-010: the completion is not `failed` due to E4. It may be
        // `passed` or an honest non-success from OTHER gates (e.g. the
        // senior critic flagging a test_gap), but never `failed` caused by
        // E4 hard on a behavior-preserving refactor.
        $this->assertNotSame(
            'failed',
            $result->completionState,
            'VAL-M2-010: a behavior-preserving pure refactor must not be failed by E4 hard. Got: '
            .$result->completionState,
        );

        // The harness WAS invoked (the pure modified symbol was evaluated).
        $this->assertSame(1, $harness->callCount, 'VAL-M2-010: harness invoked for the pure modified symbol');
    }

    /**
     * VAL-M2-009/010 (isolated): assert the ShadowDiffGate itself fires on
     * the divergence fixture and clears on the agreement fixture under hard
     * mode, so the hard-channel behavior is anchored to a correct gate
     * verdict (not a coincidence of the service).
     */
    public function test_e4_gate_verdict_matches_the_trip_and_clear_fixtures(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'hard']));

        // Trip fixture: divergence.
        $divergentResult = ShadowDiffResult::divergence([
            ['symbol' => 'add', 'file' => 'app/Math.php', 'input' => '[1, 2]', 'oldOutput' => '3', 'newOutput' => '-1'],
        ]);
        $tripVerdict = $gate->evaluate($divergentResult);
        $this->assertTrue($tripVerdict->tripped, 'The trip fixture (divergence) must trip the gate in hard mode.');
        $this->assertTrue($tripVerdict->shouldFailGate, 'Hard mode on divergence must route to STATUS_FAILED.');
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $tripVerdict->honestyFlags,
            'The trip fixture must carry the shadow_diff_regression flag.',
        );

        // Clear fixture: agreement (behavior-preserving).
        $agreementResult = ShadowDiffResult::agreement(
            [['symbol' => 'add', 'file' => 'app/Math.php']],
        );
        $clearVerdict = $gate->evaluate($agreementResult);
        $this->assertFalse($clearVerdict->tripped, 'The clear fixture (agreement) must NOT trip the gate in hard mode.');
        $this->assertFalse($clearVerdict->shouldFailGate, 'Hard mode on agreement must NOT route to STATUS_FAILED.');
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
            rawIntent: 'Fix app/Math.php',
            normalizedIntent: 'Fix app/Math.php',
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

    private function makeExecutor(ReceiptStorage $storage, string $gatewayStdout, FakeShadowDiffHarness $harness): PipelineRunExecutor
    {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $gatewayStdout));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        // Bind the fake harness so the ShadowDiffService uses it (no real
        // subprocess spawns). Bound under the canonical container key the
        // executor resolves.
        $container->instance('atlas_dev.e4.shadow_diff_harness', $harness);

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
     * Initialize a git workspace and commit the OLD pure function as the HEAD
     * baseline. The executor's diff application will later produce the NEW
     * version in the workspace file; the shadow-diff service reads the OLD
     * from git HEAD and the NEW from the workspace.
     */
    private function setupGitWorkspaceWithPureFunction(string $oldContent): void
    {
        $this->gitIn($this->tmpWorkspace, ['init', '-q']);
        $this->gitIn($this->tmpWorkspace, ['config', 'user.email', 'atlas-test@example.local']);
        $this->gitIn($this->tmpWorkspace, ['config', 'user.name', 'Atlas Test']);

        $target = $this->tmpWorkspace.'/app/Math.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, $oldContent);
        $this->gitIn($this->tmpWorkspace, ['add', 'app/Math.php']);
        $this->gitIn($this->tmpWorkspace, ['commit', '-m', 'fixture old version']);
    }

    private function loadReceipt(ReceiptStorage $storage, string $runId): VerificationReceipt
    {
        $payload = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($payload, 'verification_receipt.json must be persisted');

        return VerificationReceipt::fromArray($payload);
    }

    private function gitIn(string $workspace, array $args): void
    {
        $process = new Process(['git', ...$args], $workspace, null, null, 10.0);
        $process->run();
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
 * Unit-test fake harness. Queues scripted outputs so the shadow-diff
 * pipeline is exercised without spawning real PHP subprocesses. Mirrors the
 * Feature-test FakeShadowDiffHarness.
 */
final class FakeShadowDiffHarness implements ShadowDiffHarness
{
    public int $callCount = 0;

    /** @var list<ShadowDiffHarnessResult> */
    private array $queue = [];

    public function queueExecuted(array $oldOutputs, array $newOutputs): void
    {
        $this->queue[] = ShadowDiffHarnessResult::executed($oldOutputs, $newOutputs);
    }

    public function queueFailed(string $reason): void
    {
        $this->queue[] = ShadowDiffHarnessResult::failed($reason);
    }

    public function shadowDiff(string $oldBodySource, string $newBodySource, array $probeInputs): ShadowDiffHarnessResult
    {
        $this->callCount++;

        return array_shift($this->queue) ?? ShadowDiffHarnessResult::executed([], []);
    }
}
