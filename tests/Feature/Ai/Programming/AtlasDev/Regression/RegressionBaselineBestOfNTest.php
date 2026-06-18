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
 * E5 -- VAL-E5-013: shared pre-patch regression baseline across the M4
 * best-of-N candidate path.
 *
 * Every best-of-N candidate MUST diff against the SAME clean pre-patch
 * baseline; a candidate that breaks a baseline-green test is detected
 * regardless of candidate order; an earlier candidate's applied patch MUST
 * NOT contaminate a later candidate's baseline.
 *
 * Structural, model-irrelevant: driven through the fake-provider harness
 * (FakeClaudeCliGateway + FakeCommandRunner + scripted hermes_cli provider)
 * with N≥2 candidates and E5 active.
 */
final class RegressionBaselineBestOfNTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e5-bon-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e5-bon-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E5-013: across best-of-N candidates, all candidates share ONE
     * clean pre-patch baseline (captured before the loop) and every
     * candidate's regression is diffed against that same baseline hash.
     *
     * The best-of-N summary carries the shared baseline hash plus
     * per-candidate regression evidence, proving no candidate used a
     * different baseline.
     */
    public function test_val_e5_013_all_candidates_share_single_pre_patch_baseline_hash(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(
            e5Mode: 'advisory',
            baselineRunner: $baselineRunner,
            candidateCount: 3,
        );

        $runId = 'dev-e5-bon-013a-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $result = $this->executeRun($executor, $runId);

        $summary = $result->providerCallSummary['best_of_n'] ?? null;
        $this->assertNotNull($summary, 'best-of-N summary must be present');
        $this->assertTrue($summary['enabled'] ?? false, 'best-of-N must be enabled');

        // VAL-E5-013: a shared baseline hash must be present in the summary
        // and be non-empty (captured once on the clean tree before the loop).
        $sharedBaselineHash = $summary['e5_shared_baseline_hash'] ?? null;
        $this->assertNotNull(
            $sharedBaselineHash,
            'VAL-E5-013: best-of-N summary must carry the shared baseline hash',
        );
        $this->assertNotEmpty(
            $sharedBaselineHash,
            'VAL-E5-013: shared baseline hash must be non-empty',
        );

        // VAL-E5-013: every candidate's regression evidence references the
        // SAME shared baseline hash (no candidate used a different baseline).
        $candidates = $summary['candidates'] ?? [];
        $this->assertGreaterThanOrEqual(
            2,
            count($candidates),
            'best-of-N summary must list per-candidate evidence for N>=2',
        );
        foreach ($candidates as $candidate) {
            $this->assertSame(
                $sharedBaselineHash,
                $candidate['e5_baseline_hash'] ?? null,
                'VAL-E5-013: every candidate references the same shared baseline hash',
            );
            $this->assertArrayHasKey(
                'e5_regression_count',
                $candidate,
                'VAL-E5-013: per-candidate regression count must be present',
            );
        }

        // VAL-E5-013: the baseline was captured exactly ONCE (before the
        // candidate loop), never per-candidate.
        $this->assertSame(
            1,
            $baselineRunner->captureCount,
            'VAL-E5-013: baseline captured exactly once (not per-candidate)',
        );
    }

    /**
     * VAL-E5-013: a candidate that breaks a baseline-green test is detected
     * regardless of candidate order. Candidate 2 introduces a regression
     * (validation test was green in baseline, fails post-patch); the
     * per-candidate evidence records that regression.
     */
    public function test_val_e5_013_regression_detected_per_candidate_against_shared_baseline(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        // Per-candidate gate outcomes: c1 green, c2 REGRESSION (test fails),
        // c3 green. The FakeCommandRunner is queued accordingly.
        $commandRunner = new FakeCommandRunner;

        $executor = $this->buildExecutorWithRunner(
            e5Mode: 'advisory',
            baselineRunner: $baselineRunner,
            commandRunner: $commandRunner,
            candidateCount: 3,
        );

        $runId = 'dev-e5-bon-013b-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // Queue 3 gate evaluations. Candidate 2 fails the validation test
        // (which was green in the baseline => regression).
        $this->queueGreenGate($commandRunner); // c1: all green
        $this->queueRegressionGate($commandRunner); // c2: test fails
        $this->queueGreenGate($commandRunner); // c3: all green

        $result = $this->executeRunWithScriptedCandidates($executor, $runId);

        $summary = $result->providerCallSummary['best_of_n'] ?? null;
        $this->assertNotNull($summary);

        $candidates = $summary['candidates'] ?? [];
        $this->assertSame(3, count($candidates), '3 candidates evaluated');

        // Candidate 2 (index 1) must show a non-zero regression count.
        $c2 = $candidates[1];
        $this->assertGreaterThan(
            0,
            $c2['e5_regression_count'],
            'VAL-E5-013: candidate 2 has regressions (validation test was green, now fails)',
        );
        $this->assertNotEmpty(
            $c2['e5_regressions'],
            'VAL-E5-013: candidate 2 regression set is non-empty',
        );

        // Candidates 1 and 3 have zero regressions.
        $this->assertSame(0, $candidates[0]['e5_regression_count'], 'candidate 1 has no regressions');
        $this->assertSame(0, $candidates[2]['e5_regression_count'], 'candidate 3 has no regressions');

        // All reference the same shared baseline.
        $sharedHash = $summary['e5_shared_baseline_hash'];
        foreach ($candidates as $candidate) {
            $this->assertSame($sharedHash, $candidate['e5_baseline_hash']);
        }
    }

    /**
     * VAL-E5-013: an earlier candidate's applied patch NEVER contaminates a
     * later candidate's baseline. The baseline is captured ONCE before the
     * loop; the hash is identical regardless of how many candidates run.
     *
     * This is proven by the captureCount: the baseline runner is invoked
     * exactly once, not N times. Each candidate's diff is against the
     * iteration-0 baseline.
     */
    public function test_val_e5_013_no_candidate_patch_contaminates_later_baseline(): void
    {
        $baselineRunner = $this->captureBaselineRunner();
        $executor = $this->buildExecutor(
            e5Mode: 'advisory',
            baselineRunner: $baselineRunner,
            candidateCount: 3,
        );

        $runId = 'dev-e5-bon-013c-'.bin2hex(random_bytes(3));
        $this->seedRun(new ReceiptStorage($this->tmpStorage), $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        $this->executeRun($executor, $runId);

        // The baseline runner was called exactly ONCE (during capture, before
        // the candidate loop). If a candidate's patch contaminated the
        // baseline, we would see captureCount > 1 (recaptured per candidate).
        $this->assertSame(
            1,
            $baselineRunner->captureCount,
            'VAL-E5-013: baseline captured once; no candidate recaptured it',
        );

        // Each call was for the validation command (proving the baseline was
        // captured on the clean tree, not on a patched tree).
        $this->assertContains(
            '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            $baselineRunner->capturedCommands,
        );
    }

    /**
     * VAL-E5-013: regression detected regardless of candidate order.
     *
     * Run with a regression in candidate 1 (first) vs candidate 2 (second)
     * and assert the per-candidate evidence shows the regression in BOTH
     * orderings (detection is position-independent).
     */
    public function test_val_e5_013_regression_detected_regardless_of_candidate_order(): void
    {
        // Ordering A: regression in candidate 1 (first).
        $summaryA = $this->runBestOfNWithRegressionAt(candidateWithRegression: 0);

        // Ordering B: regression in candidate 2 (second).
        $summaryB = $this->runBestOfNWithRegressionAt(candidateWithRegression: 1);

        // Both orderings detect the regression in the right candidate.
        $this->assertGreaterThan(
            0,
            $summaryA['candidates'][0]['e5_regression_count'],
            'VAL-E5-013: regression in candidate 1 detected when it is first',
        );
        $this->assertSame(
            0,
            $summaryA['candidates'][1]['e5_regression_count'],
            'candidate 2 has no regression in ordering A',
        );

        $this->assertSame(
            0,
            $summaryB['candidates'][0]['e5_regression_count'],
            'candidate 1 has no regression in ordering B',
        );
        $this->assertGreaterThan(
            0,
            $summaryB['candidates'][1]['e5_regression_count'],
            'VAL-E5-013: regression in candidate 2 detected when it is second',
        );

        // Both orderings share the same baseline structure (same hash).
        $this->assertSame(
            $summaryA['e5_shared_baseline_hash'],
            $summaryB['e5_shared_baseline_hash'],
            'VAL-E5-013: identical baseline => identical hash regardless of candidate order',
        );
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Run a 2-candidate best-of-N where the candidate at $candidateWithRegression
     * has a regression (validation test was green in baseline, fails post-patch)
     * and the other candidate is clean.
     *
     * @return array<string,mixed> the best-of-N summary.
     */
    private function runBestOfNWithRegressionAt(int $candidateWithRegression): array
    {
        $baselineRunner = $this->captureBaselineRunner();
        $commandRunner = new FakeCommandRunner;

        $runId = 'dev-e5-bon-013d-'.$candidateWithRegression.'-'.bin2hex(random_bytes(2));
        $storagePath = $this->tmpStorage.'-'.$candidateWithRegression;
        mkdir($storagePath, 0o755, true);
        $storage = new ReceiptStorage($storagePath);
        $this->seedRun($storage, $runId, 'repair', 'R2');

        config()->set('atlas_dev.elevations.e5.mode', 'advisory');
        config()->set('atlas_dev.best_of_n.candidate_count', 2);

        $baselineService = new RegressionBaselineService($baselineRunner);
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', $baselineService);
        $executor = new PipelineRunExecutor($container, $storage);

        $workspace = sys_get_temp_dir().'/atlas-dev-e5-bon-013d-'.$candidateWithRegression.'-'.bin2hex(random_bytes(2));
        mkdir($workspace, 0o755, true);

        try {
            $this->setupCleanWorkspaceWithFileIn('app/Foo.php', $workspace);

            for ($i = 0; $i < 2; $i++) {
                if ($i === $candidateWithRegression) {
                    $this->queueRegressionGate($commandRunner);
                } else {
                    $this->queueGreenGate($commandRunner);
                }
            }

            $result = $this->executeRunWithScriptedCandidatesIn($executor, $runId, $workspace);

            return $result->providerCallSummary['best_of_n'];
        } finally {
            $this->rmrf($workspace);
            $this->rmrf($storagePath);
        }
    }

    /**
     * Create a baseline runner that records captured commands and returns
     * success for all baseline queries (clean tree, pre-patch).
     */
    private function captureBaselineRunner(): object
    {
        return new class implements RegressionBaselineRunner
        {
            public array $capturedCommands = [];

            public int $captureCount = 0;

            public function run(string $command, string $workspace): VerificationCommandResult
            {
                $this->capturedCommands[] = $command;
                $this->captureCount++;

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
        int $candidateCount,
    ): PipelineRunExecutor {
        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = true;

        return $this->buildExecutorWithRunner($e5Mode, $baselineRunner, $commandRunner, $candidateCount);
    }

    private function buildExecutorWithRunner(
        string $e5Mode,
        object $baselineRunner,
        FakeCommandRunner $commandRunner,
        int $candidateCount,
    ): PipelineRunExecutor {
        config()->set('atlas_dev.elevations.e5.mode', $e5Mode);
        config()->set('atlas_dev.best_of_n.candidate_count', $candidateCount);

        $baselineService = new RegressionBaselineService($baselineRunner);

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e5.regression_baseline_service', $baselineService);

        return new PipelineRunExecutor($container, new ReceiptStorage($this->tmpStorage));
    }

    private function setupCleanWorkspaceWithFile(string $relativePath): void
    {
        $this->setupCleanWorkspaceWithFileIn($relativePath, $this->tmpWorkspace);
    }

    private function setupCleanWorkspaceWithFileIn(string $relativePath, string $workspace): void
    {
        $this->initGitWorkspaceIn($workspace);
        $target = $workspace.'/'.$relativePath;
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->gitIn($workspace, ['add', $relativePath]);
        $this->gitIn($workspace, ['commit', '-m', 'fixture']);
    }

    private function executeRun(PipelineRunExecutor $executor, string $runId): mixed
    {
        return $this->executeRunWithScriptedCandidatesIn($executor, $runId, $this->tmpWorkspace);
    }

    private function executeRunWithScriptedCandidates(PipelineRunExecutor $executor, string $runId): mixed
    {
        return $this->executeRunWithScriptedCandidatesIn($executor, $runId, $this->tmpWorkspace);
    }

    private function executeRunWithScriptedCandidatesIn(
        PipelineRunExecutor $executor,
        string $runId,
        string $workspace,
    ): mixed {
        $this->registerFakeHermesProvider($workspace.'/app/Foo.php');

        $envelope = $this->buildEnvelope($workspace);
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
     * Queue a green gate evaluation. The gate runs validation commands FIRST
     * (from mergeCommands), then floor commands (php -l). So we queue the
     * test result first, then the php -l result.
     */
    private function queueGreenGate(FakeCommandRunner $runner): void
    {
        // Validation command (runs first per mergeCommands ordering).
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 50,
        ));
        // Floor: php -l (runs second).
        $runner->queue(new VerificationCommandResult(
            command: 'php -l app/Foo.php',
            exitCode: 0, stdout: 'No syntax errors detected', stderr: '', durationMs: 5,
        ));
    }

    /**
     * Queue a gate where the validation test FAILS (regression: was green
     * in baseline, now fails).
     */
    private function queueRegressionGate(FakeCommandRunner $runner): void
    {
        // Validation command FAILS (regression).
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1, stdout: 'FAILURES! regression', stderr: '', durationMs: 50,
        ));
        // Floor: php -l still passes.
        $runner->queue(new VerificationCommandResult(
            command: 'php -l app/Foo.php',
            exitCode: 0, stdout: 'No syntax errors detected', stderr: '', durationMs: 5,
        ));
    }

    private function initGitWorkspaceIn(string $workspace): void
    {
        $this->gitIn($workspace, ['init', '-q']);
        $this->gitIn($workspace, ['config', 'user.email', 'atlas-test@example.local']);
        $this->gitIn($workspace, ['config', 'user.name', 'Atlas Test']);
    }

    /**
     * @param  list<string>  $args
     */
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
