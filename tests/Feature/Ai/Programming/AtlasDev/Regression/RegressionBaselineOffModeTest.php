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
use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E5 -- VAL-E5-011: with e5.mode=off, behavior is byte-identical to the
 * pre-elevation baseline.
 *
 * Asserts the off-mode contract:
 *   - no regression flag (RegressionBaselineGate is a no-op)
 *   - no caller expansion (codeGraph = [], conventional floor only)
 *   - selected_existing_tests equals the M1-floor (no E5 widening)
 *   - no false regressions
 *   - frozen M1-M5 preserved (tested by the frozen suite)
 *
 * Also covers the best-of-N off-mode path: the baseline is NOT captured,
 * the summary carries no E5 artifacts, and the run is byte-identical to
 * pre-E5.
 */
final class RegressionBaselineOffModeTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    /**
     * The caller test file created at base_path() so the analyzer's
     * File::exists(base_path($test)) existence check passes. Used to prove
     * that even when a caller test EXISTS on disk, off mode does NOT expand
     * the selection to include it.
     */
    private string $callerTestPath = 'tests/Unit/E5OffModeCallerFixtureTest.php';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e5-off-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e5-off-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);

        // Create the caller test fixture at base_path() so the analyzer's
        // File::exists() would admit it -- proving off mode does NOT do so.
        file_put_contents(
            base_path($this->callerTestPath),
            "<?php\n// E5 off-mode caller fixture.\ntest(true);\n",
        );

        // Boot CI tables so caller-test selection COULD run if E5 were on.
        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->callerTestPath));

        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E5-011: with e5.mode=off, no regression flag is ever raised.
     *
     * A benign diff (no regressions) with off mode => no
     * FLAG_REGRESSION_DETECTED in any part of the result.
     */
    public function test_val_e5_011_off_mode_no_regression_flag(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(e5Mode: 'off', baselineRunner: $baselineRunner);

        $runId = 'dev-e5-off-flag-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        // VAL-E5-011: no regression flag raised. The completion must not
        // carry FLAG_REGRESSION_DETECTED (the gate is a no-op in off mode).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-E5-011: off mode => gate not failed by E5',
        );

        // Baseline runner was NEVER invoked (byte-identical to pre-E5).
        $this->assertSame(
            [],
            $baselineRunner->capturedCommands,
            'VAL-E5-011: off mode => baseline runner NEVER invoked',
        );
    }

    /**
     * VAL-E5-011: with e5.mode=off, no caller expansion happens. The
     * verification floor equals the conventional M1-floor (no CodeGraph-
     * widened tests).
     *
     * Even when the CI tables ARE present and a caller test EXISTS on disk,
     * off mode does NOT expand selected_existing_tests to include it.
     */
    public function test_val_e5_011_off_mode_no_caller_expansion_selection_equals_floor(): void
    {
        // Seed a caller relationship: App\\Foo is called by app/Bar.php
        // whose test is the caller test fixture.
        $this->seedSymbol('App\\Foo', 'app/Foo.php');
        $this->seedCallerTest('App\\Foo', 'app/Bar.php', $this->callerTestPath);

        // Intercept whether the caller test is ever run.
        $commandRunner = $this->interceptingRunner(failingSubstrings: []);

        $executor = $this->buildExecutorWithRunner(
            e5Mode: 'off',
            baselineRunner: $this->captureBaselineRunner(),
            commandRunner: $commandRunner,
        );

        $runId = 'dev-e5-off-floor-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRunWithRunner($executor, $runId, $commandRunner);

        // VAL-E5-011: off mode => caller test NOT in the floor. The caller
        // test file exists on disk and the CI tables are seeded, but off
        // mode does NOT query CodeGraph (resolveCallerTestCodeGraph returns []).
        $ranCallerTest = false;
        foreach ($commandRunner->calls as $call) {
            if (str_contains($call['command'], $this->callerTestPath)) {
                $ranCallerTest = true;
                break;
            }
        }
        $this->assertFalse(
            $ranCallerTest,
            'VAL-E5-011: off mode => caller test NOT in selection (equals conventional floor)',
        );
    }

    /**
     * VAL-E5-011: with e5.mode=off and a benign diff on the best-of-N path,
     * the baseline is NOT captured, no E5 artifacts appear in the summary,
     * and the run is byte-identical to pre-E5.
     */
    public function test_val_e5_011_off_mode_best_of_n_byte_identical(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(
            e5Mode: 'off',
            baselineRunner: $baselineRunner,
            candidateCount: 3,
        );

        $runId = 'dev-e5-off-bon-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        // VAL-E5-011: baseline NEVER captured on the best-of-N path when off.
        $this->assertSame(
            [],
            $baselineRunner->capturedCommands,
            'VAL-E5-011: off mode => baseline runner NEVER invoked (best-of-N path)',
        );

        // VAL-E5-011: the best-of-N summary must NOT carry any E5 artifacts
        // (no shared_baseline_hash, no per-candidate regression info).
        $summary = $result->providerCallSummary['best_of_n'] ?? null;
        if ($summary !== null) {
            $this->assertArrayNotHasKey(
                'e5_shared_baseline_hash',
                $summary,
                'VAL-E5-011: off mode => no E5 baseline hash in best-of-N summary',
            );
        }

        // The completion is not affected by E5 (no regression flag).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-E5-011: off mode => gate not failed by E5 (best-of-N path)',
        );
    }

    /**
     * VAL-E5-011: off mode produces no false regressions. A diff where ALL
     * validation tests pass post-patch yields no regression flag and no
     * STATUS_FAILED, identical to pre-E5.
     */
    public function test_val_e5_011_off_mode_no_false_regressions(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(e5Mode: 'off', baselineRunner: $baselineRunner);

        $runId = 'dev-e5-off-nofalse-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        // No regression possible: baseline was not captured, no regression
        // check ran. The gate result is whatever the conventional floor
        // produced (green for a benign diff).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-E5-011: off mode => no false regression failure',
        );
    }

    // -- Helpers --------------------------------------------------------------

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

    private function buildExecutor(
        string $e5Mode,
        object $baselineRunner,
        int $candidateCount = 1,
    ): PipelineRunExecutor {
        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = true;

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', new RegressionBaselineService($baselineRunner));

        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);
        config()->set('atlas_dev.best_of_n.candidate_count', $candidateCount);

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

    private function buildExecutorWithRunner(
        string $e5Mode,
        object $baselineRunner,
        object $commandRunner,
        int $candidateCount = 1,
    ): PipelineRunExecutor {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);
        config()->set('atlas_dev.best_of_n.candidate_count', $candidateCount);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', new RegressionBaselineService($baselineRunner));

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

    /**
     * Build an intercepting command runner that records all calls and
     * optionally fails commands matching the given substrings.
     *
     * @param  list<string>  $failingSubstrings
     */
    private function interceptingRunner(array $failingSubstrings): object
    {
        return new class($failingSubstrings) implements VerificationCommandRunner
        {
            /** @var list<array{command:string,workspace:string,timeout:int}> */
            public array $calls = [];

            /** @param  list<string>  $failingSubstrings */
            public function __construct(private readonly array $failingSubstrings) {}

            public function run(string $command, string $workspace, int $timeoutSeconds): VerificationCommandResult
            {
                $this->calls[] = [
                    'command' => $command,
                    'workspace' => $workspace,
                    'timeout' => $timeoutSeconds,
                ];

                $rejected = UnsafeCommandPolicy::reasonIfUnsafe($command);
                if ($rejected !== null) {
                    return new VerificationCommandResult(
                        command: $command,
                        exitCode: 126,
                        stdout: '',
                        stderr: 'rejected: '.$rejected,
                        durationMs: 0,
                        rejectedReason: $rejected,
                    );
                }

                foreach ($this->failingSubstrings as $needle) {
                    if ($needle !== '' && str_contains($command, $needle)) {
                        return new VerificationCommandResult(
                            command: $command,
                            exitCode: 1,
                            stdout: 'FAILURES!',
                            stderr: 'simulated failure for: '.$needle,
                            durationMs: 0,
                        );
                    }
                }

                return new VerificationCommandResult(
                    command: $command,
                    exitCode: 0,
                    stdout: 'ok (intercepting runner)',
                    stderr: '',
                    durationMs: 0,
                );
            }
        };
    }

    private function setupCleanWorkspaceWithFile(string $relativePath): void
    {
        $this->initGitWorkspace();
        $target = $this->tmpWorkspace.'/'.$relativePath;
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', $relativePath]);
        $this->git(['commit', '-m', 'fixture']);
    }

    private function executeRun(PipelineRunExecutor $executor, string $runId): mixed
    {
        return $this->executeRunWithRunner($executor, $runId, null);
    }

    private function executeRunWithRunner(PipelineRunExecutor $executor, string $runId, ?object $runner): mixed
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

    /**
     * Seed a code symbol into the CI table for caller-test selection.
     */
    private function seedSymbol(string $symbolName, string $filePath): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'module_id' => null,
            'symbol_type' => 'class',
            'symbol_name' => $symbolName,
            'file_path' => $filePath,
            'line_start' => 10,
            'line_end' => 20,
            'language' => 'php',
            'signature' => null,
            'namespace' => 'App',
            'parent_symbol' => null,
            'visibility' => 'public',
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => hash('sha256', $symbolName.$filePath),
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'indexed_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed a caller relationship: $callerFile references $calleeSymbol and
     * has a caller test at $testPath.
     */
    private function seedCallerTest(string $calleeSymbol, string $callerFile, string $testPath): void
    {
        $relations = [
            'symbol_references' => [
                [
                    'symbol' => $calleeSymbol,
                    'kind' => 'call',
                    'file_path' => $callerFile,
                    'line' => 42,
                ],
            ],
            'test_targets' => [
                [
                    'symbol' => $calleeSymbol,
                    'kind' => 'covers',
                    'file_path' => $callerFile,
                    'test_path' => $testPath,
                ],
            ],
            'dependencies' => [],
        ];

        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'file_path' => $callerFile,
            'module_slug' => 'test-module',
            'language' => 'php',
            'source_hash' => hash('sha256', $callerFile),
            'file_size' => 100,
            'symbols_json' => '[]',
            'relations_json' => json_encode($relations, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'indexed_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
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
