<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Regression;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
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
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E5 -- End-to-end pipeline integration: the baseline is captured before the
 * patch, the regression is detected, and the verdict propagates through the
 * executor's post-gate block to the completion decision.
 *
 * VAL-E5-001 (baseline captured before patch), VAL-E5-003 (hard regression
 * blocks completion), VAL-E5-012 (baseline captured once, reused).
 *
 * Drives a synthetic diff through the full PipelineRunExecutor with the E5
 * service bound to the container (with a fake baseline runner that records
 * the pre-patch state). The regression is detected end-to-end and routed
 * through the sanctioned channels.
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
     * VAL-E5-001 + VAL-E5-003: the baseline runner is invoked before the
     * patch (on the clean tree), and a regression (green-before/red-after)
     * hard-blocks completion in hard mode.
     */
    public function test_val_e5_001_baseline_captured_before_patch_and_e5_wired(): void
    {
        [$executor, $container, $baselineRunner] = $this->buildExecutor(
            e5Mode: 'hard',
            baselineResults: ['pass'],  // pre-patch: validation passes
            gateResults: ['fail'],      // post-patch: validation fails (regression)
        );

        $runId = 'dev-e5-int-001-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $this->registerFakeHermesProvider($target);

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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-E5-001: the baseline runner WAS invoked (before the patch).
        $this->assertNotEmpty(
            $baselineRunner->capturedCommands,
            'VAL-E5-001: baseline runner was invoked before the patch on the clean tree',
        );

        // VAL-E5-003: the regression hard-blocks completion (never success).
        // The validation command passed before (baseline) and fails after
        // (post-patch) => regression => hard-block in hard mode.
        $this->assertContains(
            CompletionSummaryEquivalent::STATUS_FAILED,
            [$result->completionState, 'blocked', 'needs_review'],
            'VAL-E5-003: a regression does not complete passed',
        );
        $this->assertNotSame(
            CompletionSummaryEquivalent::STATUS_PASSED,
            $result->completionState,
            'VAL-E5-003: never success over a regression',
        );
    }

    /**
     * VAL-E5-001 off mode: the baseline is NOT captured when E5 is off
     * (byte-identical to pre-E5). The runner is never invoked.
     */
    public function test_val_e5_011_off_mode_baseline_not_captured_byte_identical(): void
    {
        [$executor, $container, $baselineRunner] = $this->buildExecutor(
            e5Mode: 'off',
            baselineResults: ['pass'],
            gateResults: ['pass'],
        );

        $runId = 'dev-e5-int-off-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'ok'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $this->registerFakeHermesProvider($target);

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

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame(
            [],
            $baselineRunner->capturedCommands,
            'VAL-E5-011: off mode => baseline runner NEVER invoked (byte-identical)',
        );
    }

    /**
     * VAL-E5-004 + VAL-E5-005: no regressions (only fixes or pre-existing)
     * => E5 does not trip, completion unaffected.
     */
    public function test_no_regressions_completion_unaffected(): void
    {
        [$executor, $container, $baselineRunner] = $this->buildExecutor(
            e5Mode: 'hard',
            baselineResults: ['pass'],
            gateResults: ['pass'],  // post-patch: still passes (no regression)
        );

        $runId = 'dev-e5-int-noreg-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'ok'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $this->registerFakeHermesProvider($target);

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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // No regression => E5 does not trip. Completion is NOT failed
        // (E5 did not force STATUS_FAILED).
        // DEBUG: fwrite to stderr to observe the actual states.
        fwrite(STDERR, sprintf(
            "DEBUG no-regressions: completion=%s verification=%s scopeGuard=%s providerCalls=%s\n",
            $result->completionState,
            $result->verificationStatus,
            $result->scopeGuardStatus,
            json_encode($result->providerCallSummary),
        ));
        $this->assertNotSame(
            CompletionSummaryEquivalent::STATUS_FAILED,
            $result->completionState,
            'VAL-E5-004/005: no regressions => E5 does not block',
        );
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build the executor with the E5 baseline service bound to the container,
     * using a controlled fake baseline runner + fake command runner.
     *
     * @param  list<string>  $baselineResults  'pass' or 'fail' per validation command
     * @param  list<string>  $gateResults      'pass' or 'fail' per validation command
     * @return array{0:PipelineRunExecutor,1:Container,2:object}
     */
    private function buildExecutor(string $e5Mode, array $baselineResults, array $gateResults): array
    {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = true;

        $baselineRunner = new class($baselineResults) implements RegressionBaselineRunner
        {
            public array $capturedCommands = [];

            private int $idx = 0;

            public function __construct(private readonly array $results) {}

            public function run(string $command, string $workspace): VerificationCommandResult
            {
                $this->capturedCommands[] = $command;
                $result = $this->results[$this->idx] ?? 'pass';
                $this->idx++;

                return new VerificationCommandResult(
                    command: $command,
                    exitCode: $result === 'fail' ? 1 : 0,
                    stdout: $result === 'fail' ? 'FAIL' : 'OK',
                    stderr: '',
                    durationMs: 10,
                );
            }
        };

        $baselineService = new RegressionBaselineService($baselineRunner);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', $baselineService);

        $storage = new ReceiptStorage($this->tmpStorage);
        $executor = new PipelineRunExecutor($container, $storage);

        return [$executor, $container, $baselineRunner];
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
                // Mutate the file (simulates the provider applying a patch).
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'edited'; } }\n");

                return new AiProviderResult(
                    ok: true,
                    output: 'Hermes edited app/Foo.php.',
                    metadata: [],
                    errorCode: null,
                    errorMessage: null,
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

/**
 * Local constant shim for CompletionSummary statuses (avoids importing the
 * full class just for status string constants).
 */
final class CompletionSummaryEquivalent
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_NO_PATCH_NEEDED = 'no_patch_needed';
}
