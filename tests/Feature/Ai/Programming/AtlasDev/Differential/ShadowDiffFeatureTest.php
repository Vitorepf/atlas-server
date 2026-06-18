<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Differential;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarnessResult;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E4 -- VAL-E4-005/006/007/008/011/010: shadow-diff for pure functions.
 *
 * Each test drives a synthetic diff through PipelineRunExecutor::execute()
 * with a fake hermes_cli provider that writes a pure-function file change,
 * and a fake {@see ShadowDiffHarness} (bound via the container) that scripts
 * the old vs new outputs. Asserts the shadow_diff_regression flag fires
 * through the correct sanctioned channel based on e4.mode.
 *
 * Structural, model-irrelevant: driven through the fake-provider harness
 * (FakeClaudeCliGateway + FakeCommandRunner + scripted hermes_cli provider)
 * with a scripted shadow-diff harness (no real PHP subprocess).
 */
final class ShadowDiffFeatureTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-shadow-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-shadow-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E4-005: a pure-function change diverging on an input NOT covered
     * by unit tests (suite stays green) is caught: shadow_diff_regression
     * fires DESPITE the green gate; completion downgraded/blocked.
     */
    public function test_val_e4_005_pure_function_regression_fires_flag_despite_green_gate(): void
    {
        $runId = 'dev-shadow-005-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithPureFunction('app/Math.php', 'add');

        // The fake provider changes `add` from `$x + $y` to `$x - $y`
        // (a regression). The unit test (FooTest) still passes because it
        // only tests (0,0) which is 0 either way.
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Math.php', 'minus');

        // Script the shadow-diff harness to return diverging old/new outputs
        // (add vs subtract over [(0,0),(1,2),(3,4)]).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0', '3', '7'], ['0', '-1', '-1']);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 1);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');

        $executor = $this->buildExecutor($commandRunner, $storage, $harness);

        $result = $this->executeRun($executor, $runId);
        $honestyFlags = $this->readHonestyFlags($storage, $runId);

        // VAL-E4-005: the unit gate is green (test passes) BUT the shadow-diff
        // divergence forces a flag and downgrades to needs_review.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-E4-005: unit gate stays passed (the regression is not unit-tested)',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $result->completionState,
            'VAL-E4-005: shadow_diff_regression flag downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $honestyFlags,
            'VAL-E4-005: shadow_diff_regression flag present despite green gate',
        );
        // The harness WAS invoked (pure modified symbol).
        $this->assertSame(1, $harness->callCount, 'VAL-E4-005: harness invoked once for the pure modified symbol');
    }

    /**
     * VAL-E4-006: shadow_diff_regression is a regression verdict, never
     * silently passed. Advisory => needs_review; hard => STATUS_FAILED.
     */
    public function test_val_e4_006_advisory_downgrades_hard_fails_gate(): void
    {
        // -- ADVISORY: flag + needs_review, gate stays passed.
        $resultAdvisory = $this->runShadowDivergent('advisory', 'minus');
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $resultAdvisory->verificationStatus,
            'VAL-E4-006 advisory: gate stays passed (flag does not force STATUS_FAILED)',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $resultAdvisory->completionState,
            'VAL-E4-006 advisory: completion downgraded to needs_review',
        );
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $this->readHonestyFlagsFromResult($resultAdvisory),
        );

        // -- HARD: STATUS_FAILED on the gate.
        $resultHard = $this->runShadowDivergent('hard', 'minus');
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $resultHard->verificationStatus,
            'VAL-E4-006 hard: gate forced to STATUS_FAILED on shadow-diff regression',
        );
        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $resultHard->completionState,
            'VAL-E4-006 hard: completion is NOT passed',
        );
    }

    /**
     * VAL-E4-007: impure code is conservatively not classified pure; no
     * shadow-diff runs and no shadow_diff_regression is raised.
     *
     * The fixture changes a function that uses a superglobal in the new
     * version. The pure-function detector skips it; the harness is NEVER
     * invoked; no flag fires even though the scripted harness would diverge.
     */
    public function test_val_e4_007_impure_function_not_shadow_diffed_no_flag(): void
    {
        $runId = 'dev-shadow-007-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithPureFunction('app/Math.php', 'add');

        // The provider writes a version using a superglobal (impure).
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Math.php', 'impure');

        // Script a divergent result that SHOULD NEVER be consumed because the
        // impure body is skipped before the harness is called.
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0'], ['999']); // would diverge, but never called

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 1);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');

        $executor = $this->buildExecutor($commandRunner, $storage, $harness);

        $result = $this->executeRun($executor, $runId);
        $honestyFlags = $this->readHonestyFlags($storage, $runId);

        // VAL-E4-007: no shadow_diff_regression flag (the function is impure).
        $this->assertNotContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $honestyFlags,
            'VAL-E4-007: impure function => no shadow_diff_regression flag',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-E4-007: green gate stays passed (no E4 shadow-diff flag)',
        );
        // VAL-E4-007: harness NEVER invoked (impure symbol skipped).
        $this->assertSame(0, $harness->callCount, 'VAL-E4-007: harness NEVER invoked on impure code');
    }

    /**
     * VAL-E4-008: a behavior-preserving pure refactor (identical outputs)
     * raises no shadow_diff_regression.
     */
    public function test_val_e4_008_behavior_preserving_refactor_no_flag(): void
    {
        $runId = 'dev-shadow-008-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithPureFunction('app/Math.php', 'double');

        // The provider rewrites `double` from `$x + $x` to `$x * 2`
        // (behavior-preserving).
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Math.php', 'double-refactor');

        // Harness scripts IDENTICAL old/new outputs (agreement).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0', '2', '-2', '84'], ['0', '2', '-2', '84']);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 1);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');

        $executor = $this->buildExecutor($commandRunner, $storage, $harness);

        $result = $this->executeRun($executor, $runId);
        $honestyFlags = $this->readHonestyFlags($storage, $runId);

        $this->assertNotContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $honestyFlags,
            'VAL-E4-008: behavior-preserving refactor => no flag',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-E4-008: stays passed',
        );
        $this->assertSame(1, $harness->callCount, 'VAL-E4-008: harness invoked (pure modified symbol) but agreed');
    }

    /**
     * VAL-E4-011: a newly-added function with no prior implementation is
     * skipped with reason; no flag, no exception.
     */
    public function test_val_e4_011_newly_added_function_skipped_no_flag_no_crash(): void
    {
        $runId = 'dev-shadow-011-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');

        // The committed file has NO function definitions; the provider ADDS one.
        $this->setupCleanWorkspaceWithEmptyFile('app/Math.php');
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Math.php', 'newly-added');

        // Harness would diverge, but it is NEVER called (no baseline to diff).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0'], ['999']);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 1);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');

        $executor = $this->buildExecutor($commandRunner, $storage, $harness);

        $result = $this->executeRun($executor, $runId);
        $honestyFlags = $this->readHonestyFlags($storage, $runId);

        $this->assertNotContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $honestyFlags,
            'VAL-E4-011: newly-added function => no shadow_diff_regression',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-E4-011: stays passed (no baseline to diff)',
        );
        $this->assertSame(0, $harness->callCount, 'VAL-E4-011: harness NEVER invoked (no prior impl to diff)');
    }

    /**
     * VAL-E4-010 (shadow-diff slice): off-mode byte-identical (no machinery
     * observable). With e4.mode=off the ShadowDiffService is NOT invoked
     * (no subprocess, no git reads), no flag, no output divergence.
     */
    public function test_val_e4_010_off_mode_byte_identical_no_shadow_diff_invocation(): void
    {
        $runId = 'dev-shadow-010-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithPureFunction('app/Math.php', 'add');

        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Math.php', 'minus');

        // The harness tracks calls. In off mode the harness MUST NEVER be
        // invoked (the service is not even resolved).
        $harness = new FakeShadowDiffHarness;
        $harness->queueExecuted(['0'], ['999']); // would diverge, but never consumed

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 1);

        config()->set('atlas_dev.elevations.e4.mode', 'off');

        $executor = $this->buildExecutor($commandRunner, $storage, $harness);

        $result = $this->executeRun($executor, $runId);
        $honestyFlags = $this->readHonestyFlags($storage, $runId);

        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-E4-010 off: gate stays passed (byte-identical)',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-E4-010 off: completion stays passed (byte-identical)',
        );
        $this->assertNotContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $honestyFlags,
            'VAL-E4-010 off: no shadow_diff_regression flag (byte-identical)',
        );
        $this->assertSame(0, $harness->callCount, 'VAL-E4-010 off: harness NEVER invoked');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Run a divergent shadow-diff scenario under a given e4.mode.
     */
    private function runShadowDivergent(string $e4Mode, string $providerVariant): mixed
    {
        $runId = 'dev-shadow-'.$e4Mode.'-'.bin2hex(random_bytes(2));
        $storagePath = $this->tmpStorage.'-'.$e4Mode;
        mkdir($storagePath, 0o755, true);
        $storage = new ReceiptStorage($storagePath);
        $this->seedRun($storage, $runId, 'repair', 'R2');

        config()->set('atlas_dev.elevations.e4.mode', $e4Mode);
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $workspace = sys_get_temp_dir().'/atlas-dev-shadow-'.$e4Mode.'-'.bin2hex(random_bytes(2));
        mkdir($workspace, 0o755, true);

        try {
            $this->setupCleanWorkspaceWithPureFunctionIn('app/Math.php', 'add', $workspace);
            $this->registerFakeHermesProvider($workspace.'/app/Math.php', $providerVariant);

            // Divergent harness outputs (add vs subtract).
            $harness = new FakeShadowDiffHarness;
            $harness->queueExecuted(['0', '3', '7'], ['0', '-1', '-1']);

            $commandRunner = new FakeCommandRunner;
            $this->queueGreenGate($commandRunner, count: 1);

            $container = new Container;
            $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
            $container->instance(VerificationCommandRunner::class, $commandRunner);
            $executor = new PipelineRunExecutor($container, $storage);

            return $this->executeRunWithHarnessIn($executor, $runId, $workspace, $container, $harness);
        } finally {
            $this->rmrf($workspace);
            $this->rmrf($storagePath);
        }
    }

    private function buildExecutor(
        FakeCommandRunner $commandRunner,
        ReceiptStorage $storage,
        FakeShadowDiffHarness $harness,
    ): PipelineRunExecutor {
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        // Bind the fake harness so the ShadowDiffService uses it (no real
        // subprocess spawns). Bound under the canonical container key the
        // executor resolves.
        $container->instance('atlas_dev.e4.shadow_diff_harness', $harness);

        return new PipelineRunExecutor($container, $storage);
    }

    private function executeRun(PipelineRunExecutor $executor, string $runId): mixed
    {
        return $this->executeRunWithHarnessIn($executor, $runId, $this->tmpWorkspace, null, null);
    }

    private function executeRunWithHarnessIn(
        PipelineRunExecutor $executor,
        string $runId,
        string $workspace,
        ?Container $container = null,
        ?FakeShadowDiffHarness $harness = null,
    ): mixed {
        // When a container is passed from runShadowDivergent, bind the harness.
        if ($container !== null && $harness !== null) {
            $container->instance('atlas_dev.e4.shadow_diff_harness', $harness);
        }

        $envelope = $this->buildEnvelope($workspace);
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Math.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'provider_lock' => [
                'provider' => 'hermes_cli',
                'model_family' => 'minimax-m3',
                'fallback_allowed' => false,
            ],
            'repair_policy' => [
                'max_attempts' => 0,
                'same_provider' => true,
                'requires_failed_gate_output' => true,
                'abort_on_same_signature_twice' => true,
            ],
        ]);

        return $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );
    }

    /**
     * Register a fake hermes_cli provider that writes a specific variant of
     * the pure function to the target file.
     *
     * Variants:
     *   - 'minus':             changes `add` from `$x + $y` to `$x - $y` (regression).
     *   - 'double-refactor':   changes `double` from `$x + $x` to `$x * 2` (behavior-preserving).
     *   - 'impure':            changes `add` to use a superglobal (impure).
     *   - 'newly-added':       adds a function to a previously-empty file.
     */
    private function registerFakeHermesProvider(string $target, string $variant): void
    {
        $fakeHermes = new class($target, $variant) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly string $variant,
            ) {}

            public function key(): string
            {
                return 'hermes_cli';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return $this->runStreaming($job, $prompt);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                file_put_contents($this->target, $this->content());

                return new AiProviderResult(
                    ok: true,
                    output: 'Hermes edited app/Math.php.',
                    command: ['hermes', 'chat', '--quiet'],
                    exitCode: 0,
                    durationMs: 100,
                    stdout: 'Hermes edited app/Math.php.',
                    stderr: '',
                    errorCode: null,
                    errorMessage: null,
                    metadata: [],
                );
            }

            private function content(): string
            {
                return match ($this->variant) {
                    'minus' => "<?php\nfunction add(int \$x, int \$y): int { return \$x - \$y; }\n",
                    'double-refactor' => "<?php\nfunction double(int \$x): int { return \$x * 2; }\n",
                    'impure' => "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y + \$_POST['z']; }\n",
                    'newly-added' => "<?php\nfunction brandNew(int \$x): int { return \$x + 1; }\n",
                    default => "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
                };
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);
    }

    private function setupCleanWorkspaceWithPureFunction(string $relativePath, string $fnName): void
    {
        $this->setupCleanWorkspaceWithPureFunctionIn($relativePath, $fnName, $this->tmpWorkspace);
    }

    private function setupCleanWorkspaceWithPureFunctionIn(string $relativePath, string $fnName, string $workspace): void
    {
        $this->initGitWorkspaceIn($workspace);
        $target = $workspace.'/'.$relativePath;
        @mkdir(dirname($target), 0o755, true);
        $content = match ($fnName) {
            'add' => "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
            'double' => "<?php\nfunction double(int \$x): int { return \$x + \$x; }\n",
            default => "<?php\nfunction add(int \$x, int \$y): int { return \$x + \$y; }\n",
        };
        file_put_contents($target, $content);
        $this->gitIn($workspace, ['add', $relativePath]);
        $this->gitIn($workspace, ['commit', '-m', 'fixture old version']);
    }

    private function setupCleanWorkspaceWithEmptyFile(string $relativePath): void
    {
        $this->initGitWorkspaceIn($this->tmpWorkspace);
        $target = $this->tmpWorkspace.'/'.$relativePath;
        @mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\n");
        $this->gitIn($this->tmpWorkspace, ['add', $relativePath]);
        $this->gitIn($this->tmpWorkspace, ['commit', '-m', 'empty fixture']);
    }

    private function buildEnvelope(string $workspace): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: 'hermes_cli',
            ),
            workspace: $workspace,
            workspaceHash: hash('sha256', $workspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
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

    private function queueGreenGate(FakeCommandRunner $runner, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $runner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: 50,
            ));
            $runner->queue(new VerificationCommandResult(
                command: 'php -l app/Math.php',
                exitCode: 0, stdout: 'No syntax errors detected', stderr: '', durationMs: 5,
            ));
        }
    }

    private function initGitWorkspaceIn(string $workspace): void
    {
        $this->gitIn($workspace, ['init', '-q']);
        $this->gitIn($workspace, ['config', 'user.email', 'atlas-test@example.local']);
        $this->gitIn($workspace, ['config', 'user.name', 'Atlas Test']);
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
 * Feature-test fake harness. Queues scripted outputs so the pipeline is
 * exercised end-to-end without spawning real PHP subprocesses.
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
