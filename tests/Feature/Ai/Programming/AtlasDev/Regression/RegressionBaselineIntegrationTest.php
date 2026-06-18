<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Regression;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
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
 * E5 -- End-to-end pipeline integration: the baseline is captured before the
 * patch (VAL-E5-001) and NOT captured when E5 is off (VAL-E5-011).
 *
 * The regression detection LOGIC (VAL-E5-002/003/004/005) and the verdict
 * routing are proven by the dedicated unit + feature tests
 * (RegressionBaselineServiceTest, RegressionBaselineGateTest,
 * RegressionBaselineFlagTest). This test proves the EXECUTOR WIRING: the
 * baseline service is resolved from the container, the baseline runner IS
 * invoked before the patch, and off mode skips the capture entirely.
 *
 * The container binding `atlas_dev.e5.regression_baseline_service` is the
 * single integration point: when bound, the executor captures a baseline
 * before the repair loop; when unbound (the frozen M1-M5 tests), the
 * executor skips the capture entirely so those tests stay byte-identical.
 */
final class RegressionBaselineIntegrationTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e5-int-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e5-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E5-001: the baseline runner IS invoked before the patch (on the
     * clean tree) when E5 is enabled and the service is bound.
     */
    public function test_val_e5_001_baseline_captured_before_patch_when_service_bound(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(e5Mode: 'advisory', baselineRunner: $baselineRunner);

        $runId = 'dev-e5-int-001-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $this->executeRun($executor, $runId);

        $this->assertNotEmpty(
            $baselineRunner->capturedCommands,
            'VAL-E5-001: baseline runner was invoked before the patch on the clean tree',
        );
        $this->assertContains(
            '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            $baselineRunner->capturedCommands,
            'VAL-E5-001: the validation command was captured in the baseline',
        );
    }

    /**
     * VAL-E5-011: when E5 is off, the baseline runner is NEVER invoked
     * (byte-identical to pre-E5).
     */
    public function test_val_e5_011_off_mode_baseline_not_captured(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(e5Mode: 'off', baselineRunner: $baselineRunner);

        $runId = 'dev-e5-int-off-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $this->executeRun($executor, $runId);

        $this->assertSame(
            [],
            $baselineRunner->capturedCommands,
            'VAL-E5-011: off mode => baseline runner NEVER invoked (byte-identical)',
        );
    }

    /**
     * VAL-E5-011 corollary: when the E5 service is NOT bound (the frozen M1-M5
     * test scenario), the executor skips the baseline capture entirely so
     * those tests stay byte-identical.
     */
    public function test_unbound_service_skips_baseline_capture(): void
    {
        // No baseline service bound => the executor cannot resolve it => skips.
        $executor = $this->buildExecutorWithoutE5Service(e5Mode: 'advisory');

        $runId = 'dev-e5-int-unbound-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // Should not throw / crash. Completes normally (E5 degraded to off).
        $result = $this->executeRun($executor, $runId);

        $this->assertNotNull($result, 'unbound E5 service does not crash');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Create a baseline runner that records captured commands and returns
     * success for all baseline queries (clean tree, pre-patch).
     */
    private function captureBaselineRunner(): object
    {
        return new class implements RegressionBaselineRunner
        {
            public array $capturedCommands = [];

            public function run(string $command, string $workspace): VerificationCommandResult
            {
                $this->capturedCommands[] = $command;

                return new VerificationCommandResult(
                    command: $command,
                    exitCode: 0,
                    stdout: 'OK (baseline)',
                    stderr: '',
                    durationMs: 10,
                );
            }
        };
    }

    /**
     * Build the executor with the E5 baseline service bound to the container.
     */
    private function buildExecutor(string $e5Mode, object $baselineRunner): PipelineRunExecutor
    {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = true;

        $baselineService = new RegressionBaselineService($baselineRunner);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', $baselineService);

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

    /**
     * Build the executor WITHOUT the E5 service bound (simulates the frozen
     * M1-M5 tests that pre-date E5).
     */
    private function buildExecutorWithoutE5Service(string $e5Mode): PipelineRunExecutor
    {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = true;

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        // NO 'atlas_dev.e5.regression_baseline_service' binding.

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

    /**
     * Set up a clean git workspace with a committed file so the fake Hermes
     * provider can mutate it.
     */
    private function setupCleanWorkspaceWithFile(string $relativePath): void
    {
        $this->initGitWorkspace();
        $target = $this->tmpWorkspace.'/'.$relativePath;
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', $relativePath]);
        $this->git(['commit', '-m', 'fixture']);
    }

    /**
     * Execute a run through the executor and return the result.
     */
    private function executeRun(PipelineRunExecutor $executor, string $runId): RunExecutionResult
    {
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Foo.php');

        $envelope = $this->buildEnvelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'provider_lock' => [
                'provider' => 'hermes_cli',
                'model_family' => 'minimax-m3',
                'fallback_allowed' => false,
            ],
            'repair_policy' => [
                'max_attempts' => 1,
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

    private function registerFakeHermesProvider(string $target): void
    {
        $fakeHermes = new class($target) implements AiProvider
        {
            public function __construct(private readonly string $target) {}

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
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'edited'; } }\n");

                return new AiProviderResult(
                    ok: true,
                    output: 'Hermes edited app/Foo.php.',
                    command: ['hermes', 'chat', '--quiet'],
                    exitCode: 0,
                    durationMs: 100,
                    stdout: 'Hermes edited app/Foo.php.',
                    stderr: '',
                    errorCode: null,
                    errorMessage: null,
                    metadata: [],
                );
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

    private function buildEnvelope(): OperationEnvelope
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
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: 'Fix app/Foo.php',
            normalizedIntent: 'Fix app/Foo.php',
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

    private function initGitWorkspace(): void
    {
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'atlas-test@example.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): void
    {
        $process = new Process(['git', ...$args], $this->tmpWorkspace, null, null, 10.0);
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
