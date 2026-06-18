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
use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
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
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E5 -- Caller-test selection end-to-end pipeline integration (red-first).
 *
 * VAL-E5-007: a patch that breaks a direct caller's test T_C (not the
 *             changed symbol's own tests) is caught and blocks via the
 *             expanded selection.
 * VAL-E5-010: safe degradation does not silently weaken regression
 *             detection -- with CI tables absent AND E5 regression baseline
 *             active, a diff that breaks a previously-green test within the
 *             conventional selection is still detected and blocked.
 *
 * The caller-test selection LOGIC (VAL-E5-006/008/009) is proven by the
 * dedicated unit test (CallerTestSelectionServiceTest). This test proves the
 * EXECUTOR WIRING: when E5 is enabled and the service is bound, the
 * expanded selection drives the verification gate to run the caller test
 * T_C, and a failing T_C surfaces as STATUS_FAILED / completion=failed.
 */
final class CallerTestSelectionFeatureTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    /**
     * The caller test file created at base_path() so the analyzer's
     * File::exists(base_path($test)) existence check passes. The analyzer
     * only adds EXISTING test files to selected_existing_tests; the caller
     * test T_C must therefore exist on disk for the floor to pick it up.
     * Created in setUp, removed in tearDown.
     */
    private string $callerTestPath = 'tests/Unit/E5CallerTestFixtureTest.php';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e5-cts-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e5-cts-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);

        // Create the caller test fixture at base_path() so the analyzer's
        // File::exists() check admits it into selected_existing_tests.
        file_put_contents(
            base_path($this->callerTestPath),
            "<?php\n// E5 caller-test fixture: created by CallerTestSelectionFeatureTest.\ntest(true);\n",
        );

        // Boot the Code Intelligence tables so CallerTestSelectionService has
        // a read-model to query. The repo's established setUp pattern for
        // code-intelligence feature tests.
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
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);

        // Remove the caller test fixture.
        $path = base_path($this->callerTestPath);
        if (file_exists($path)) {
            @unlink($path);
        }

        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * VAL-E5-007: a diff that does NOT break the changed symbol's own tests
     * but breaks a direct caller's test T_C surfaces as STATUS_FAILED because
     * the selection was expanded to include T_C.
     *
     * Scenario:
     *   - changed file: app/Foo.php (symbol App\Foo)
     *   - caller: app/Bar.php references App\Foo
     *   - caller test: tests/Unit/BarTest.php (covers App\Foo via Bar)
     *   - the patch changes Foo in a way that Bar's test breaks
     *   - the verification gate runs the EXPANDED floor which includes
     *     tests/Unit/BarTest.php -> it fails -> STATUS_FAILED.
     */
    public function test_val_e5_007_caller_test_break_caught_via_expanded_selection(): void
    {
        $this->seedSymbol('App\\Foo', 'app/Foo.php');
        $this->seedCallerTest('App\\Foo', 'app/Bar.php', $this->callerTestPath);

        // Intercepting runner: the caller test T_C FAILS, everything else passes.
        // This proves the expanded floor ran T_C.
        $commandRunner = $this->interceptingRunner(
            failingSubstrings: [$this->callerTestPath],
        );

        $executor = $this->buildExecutor(
            e5Mode: 'hard',
            commandRunner: $commandRunner,
            bindCallerSelection: true,
        );

        $runId = 'dev-e5-cts-007-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-E5-007: caller test failure drives STATUS_FAILED via expanded selection',
        );

        // Evidence: the caller test command WAS run (it appears in calls).
        $ranCallerTest = false;
        foreach ($commandRunner->calls as $call) {
            if (str_contains($call['command'], $this->callerTestPath)) {
                $ranCallerTest = true;
                break;
            }
        }
        $this->assertTrue(
            $ranCallerTest,
            'VAL-E5-007: the expanded floor actually ran the caller test T_C',
        );
    }

    /**
     * VAL-E5-007 corollary: with caller-test expansion DISABLED (e5 off),
     * the same caller-test break is NOT caught by the floor (the
     * conventional floor does not include tests/Unit/BarTest.php because
     * app/Bar.php is not a changed file). This proves the expanded selection
     * is what catches the break -- it is NOT a side channel.
     */
    public function test_val_e5_007_off_mode_conventional_floor_misses_caller_test(): void
    {
        $this->seedSymbol('App\\Foo', 'app/Foo.php');
        $this->seedCallerTest('App\\Foo', 'app/Bar.php', $this->callerTestPath);

        $commandRunner = $this->interceptingRunner(
            failingSubstrings: [$this->callerTestPath],
        );

        // Off mode: no caller expansion, conventional floor only.
        $executor = $this->buildExecutor(
            e5Mode: 'off',
            commandRunner: $commandRunner,
            bindCallerSelection: true,
        );

        $runId = 'dev-e5-cts-off-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        // Off mode: the caller test is NOT in the floor, so even though the
        // runner would fail it, it is never invoked. The gate stays non-failed
        // by the caller test (byte-identical to pre-E5 conventional floor).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'off mode: caller test NOT in conventional floor, gate not failed by it',
        );

        // Evidence: the caller test was NOT run by the floor.
        $ranCallerTest = false;
        foreach ($commandRunner->calls as $call) {
            if (str_contains($call['command'], $this->callerTestPath)) {
                $ranCallerTest = true;
                break;
            }
        }
        $this->assertFalse(
            $ranCallerTest,
            'off mode: conventional floor did NOT run the caller test',
        );
    }

    /**
     * VAL-E5-010: with CI tables absent AND E5 regression baseline active,
     * a diff that breaks a previously-green test WITHIN THE CONVENTIONAL
     * selection is still detected and blocked.
     *
     * The caller-test expansion degrades safely (tables absent => empty
     * related_tests), but the regression baseline + conventional floor still
     * catch a break in a validation command that was green before.
     */
    public function test_val_e5_010_tables_absent_conventional_regression_still_detected(): void
    {
        // Drop the CI tables to simulate a deployment without Code Intelligence.
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_file_snapshots');

        // The baseline runner records the validation command as PASSING
        // before the patch (clean tree).
        $baselineRunner = new class implements RegressionBaselineRunner
        {
            public array $captured = [];

            public function run(string $command, string $workspace): VerificationCommandResult
            {
                $this->captured[] = $command;

                return new VerificationCommandResult(
                    command: $command,
                    exitCode: 0,
                    stdout: 'OK (baseline)',
                    stderr: '',
                    durationMs: 5,
                );
            }
        };

        // The verification command runner: the validation command FAILS
        // post-patch (it was green in the baseline).
        $commandRunner = $this->interceptingRunner(
            failingSubstrings: ['tests/Unit/FooTest.php'],
        );

        $executor = $this->buildExecutor(
            e5Mode: 'hard',
            commandRunner: $commandRunner,
            bindCallerSelection: true,
            baselineRunner: $baselineRunner,
        );

        $runId = 'dev-e5-cts-010-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        // VAL-E5-010: the conventional-scoped regression is detected and
        // blocks (the validation command was green before, fails after).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-E5-010: conventional regression still detected with tables absent',
        );
    }

    /**
     * VAL-E5-009 end-to-end: with CI tables absent, the pipeline does NOT
     * crash and completes normally (degraded to conventional selection).
     */
    public function test_val_e5_009_tables_absent_pipeline_does_not_crash(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_file_snapshots');

        $commandRunner = $this->interceptingRunner(failingSubstrings: []);

        $executor = $this->buildExecutor(
            e5Mode: 'advisory',
            commandRunner: $commandRunner,
            bindCallerSelection: true,
        );

        $runId = 'dev-e5-cts-009-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // No exception. The run completes (degraded to conventional).
        $result = $this->executeRun($executor, $runId);

        $this->assertNotNull($result, 'VAL-E5-009: pipeline completes (no crash) with tables absent');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build an intercepting command runner that FAILS any command containing
     * one of the given substrings and PASSES everything else. Records every
     * call so tests can assert which commands the floor actually ran.
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

    /**
     * Build the executor with the E5 caller-test selection service bound.
     */
    private function buildExecutor(
        string $e5Mode,
        object $commandRunner,
        bool $bindCallerSelection,
        ?object $baselineRunner = null,
    ): PipelineRunExecutor {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);

        if ($bindCallerSelection) {
            $container->instance('atlas_dev.e5.caller_test_selection_service', new CallerTestSelectionService);
        }

        if ($baselineRunner !== null) {
            $container->instance(
                'atlas_dev.e5.regression_baseline_service',
                new RegressionBaselineService($baselineRunner),
            );
        }

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

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
            rawIntent: 'Edit app/Foo.php',
            normalizedIntent: 'Edit app/Foo.php',
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

    private function setupCleanWorkspaceWithFile(string $relativePath): void
    {
        $this->initGitWorkspace();
        $target = $this->tmpWorkspace.'/'.$relativePath;
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', $relativePath]);
        $this->git(['commit', '-m', 'fixture']);
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

    // -- CodeGraph seed helpers ----------------------------------------------

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

    private function seedCallerTest(string $changedSymbol, string $callerFile, string $testPath): void
    {
        $relations = [
            'symbol_references' => [
                [
                    'symbol' => $changedSymbol,
                    'kind' => 'call',
                    'file_path' => $callerFile,
                    'line' => 42,
                ],
            ],
            'test_targets' => [
                [
                    'symbol' => $changedSymbol,
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
}
