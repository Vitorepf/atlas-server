<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Differential;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceGate;
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
 * E4 -- VAL-E4-001/002/003/004/010: DifferentialTestingService invoked from
 * executeBestOfNHermes.
 *
 * Each test runs PipelineRunExecutor::execute() with a fake hermes_cli
 * provider scripted to produce N candidate diffs (either identical = agreeing
 * or distinct = divergent), and asserts the verdict surfaces through the
 * correct sanctioned channel based on e4.mode.
 *
 * Structural, model-irrelevant: driven through the fake-provider harness
 * (FakeClaudeCliGateway + FakeCommandRunner + scripted hermes_cli provider).
 */
final class CandidateDivergenceFeatureTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e4-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e4-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E4-001: Agreeing candidates yield high confidence, no divergence
     * flag, completion may remain passed.
     */
    public function test_val_e4_001_agreeing_candidates_no_flag_passed_allowed(): void
    {
        $runId = 'dev-e4-001-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // All candidates write the SAME value => agreement.
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Foo.php', identical: true);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');
        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $executor = $this->buildExecutor($commandRunner, $storage);

        $result = $this->executeRun($executor, $runId);

        // VAL-E4-001: agreement => no candidate_divergence flag
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-E4-001: agreeing candidates => verification stays passed',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-E4-001: agreeing candidates => completion may remain passed',
        );

        // The best-of-N summary carries the agreement signal.
        $summary = $result->providerCallSummary['best_of_n'] ?? null;
        $this->assertNotNull($summary, 'best-of-N summary present');
        $serialized = json_encode($summary);
        $this->assertNotFalse($serialized);
        $this->assertStringNotContainsString(
            CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE,
            $serialized,
            'VAL-E4-001: no candidate_divergence in the summary on agreement',
        );
    }

    /**
     * VAL-E4-002: Divergent candidates raise candidate_divergence carrying
     * the divergent diffs as evidence.
     */
    public function test_val_e4_002_divergent_candidates_raise_flag_with_diffs(): void
    {
        $runId = 'dev-e4-002-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // Each candidate writes a DIFFERENT value => divergence.
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Foo.php', identical: false);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');
        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $executor = $this->buildExecutor($commandRunner, $storage);

        $result = $this->executeRun($executor, $runId);

        // VAL-E4-002: the best-of-N summary carries the divergence flag and
        // the divergent diffs as evidence.
        $summary = $result->providerCallSummary['best_of_n'] ?? null;
        $this->assertNotNull($summary);
        $serialized = json_encode($summary);
        $this->assertNotFalse($serialized);
        $this->assertStringContainsString(
            CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE,
            $serialized,
            'VAL-E4-002: candidate_divergence present in the summary',
        );
        // The divergent diffs are carried as evidence.
        $this->assertArrayHasKey(
            'e4_divergent_diffs',
            $summary,
            'VAL-E4-002: divergent diffs carried in the summary',
        );
        $this->assertNotEmpty(
            $summary['e4_divergent_diffs'],
            'VAL-E4-002: divergent diffs payload is non-empty',
        );
    }

    /**
     * VAL-E4-003: Candidate divergence is NEVER silently accepted. Advisory
     * mode downgrades PASSED -> needs_review.
     */
    public function test_val_e4_003_advisory_divergence_downgrades_to_needs_review(): void
    {
        $runId = 'dev-e4-003-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, 'repair', 'R2');
        $this->setupCleanWorkspaceWithFile('app/Foo.php');

        // Divergent candidates.
        $this->registerFakeHermesProvider($this->tmpWorkspace.'/app/Foo.php', identical: false);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        config()->set('atlas_dev.elevations.e4.mode', 'advisory');
        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $executor = $this->buildExecutor($commandRunner, $storage);

        $result = $this->executeRun($executor, $runId);

        // VAL-E4-003: divergent candidates => needs_review (never clean green)
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $result->completionState,
            'VAL-E4-003: advisory divergence => needs_review, never passed',
        );
        // The verification gate stays passed (advisory never forces STATUS_FAILED);
        // the honesty flag drives the CompletionStateGate downgrade.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-E4-003: advisory does NOT force STATUS_FAILED on the gate',
        );
    }

    /**
     * VAL-E4-004 (feature-level): a green completion can never coexist with
     * candidate_divergence. The CompletionDecision ctor invariant (passed
     * forbids honesty_flags) guarantees this mechanically. This test asserts
     * the invariant throws when attempting passed + the flag.
     */
    public function test_val_e4_004_completion_decision_ctor_forbids_passed_with_flag(): void
    {
        // The CompletionSummary ctor throws when status=passed + honesty_flags.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status=passed forbids honesty_flags');

        new CompletionSummary(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE],
            residualRisks: [],
        );
    }

    /**
     * VAL-E4-010: E4 off-mode byte-identical; advisory/hard escalate correctly.
     *
     * The same divergent diff under each e4.mode:
     *   - off => no flag, completion stays passed (byte-identical to pre-E4)
     *   - advisory => flag + needs_review
     *   - hard => STATUS_FAILED on the gate
     */
    public function test_val_e4_010_mode_routing_off_advisory_hard(): void
    {
        // -- OFF mode: byte-identical, no flag, passed stays.
        $resultOff = $this->runDivergentBestOfN('off');
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $resultOff->verificationStatus,
            'VAL-E4-010 off: gate stays passed (byte-identical)',
        );
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $resultOff->completionState,
            'VAL-E4-010 off: completion stays passed (byte-identical)',
        );
        $summaryOff = $resultOff->providerCallSummary['best_of_n'] ?? null;
        $this->assertNotNull($summaryOff);
        $this->assertStringNotContainsString(
            CandidateDivergenceGate::FLAG_CANDIDATE_DIVERGENCE,
            json_encode($summaryOff) ?: '',
            'VAL-E4-010 off: no candidate_divergence flag',
        );
        $this->assertArrayNotHasKey(
            'e4_divergent_diffs',
            $summaryOff,
            'VAL-E4-010 off: no E4 artifacts in the summary (byte-identical)',
        );
        $this->assertArrayNotHasKey(
            'e4_candidate_agreed',
            $summaryOff,
            'VAL-E4-010 off: no E4 boolean indicator (byte-identical)',
        );
        $this->assertArrayNotHasKey(
            'e4_flag',
            $summaryOff,
            'VAL-E4-010 off: no e4_flag key (byte-identical)',
        );

        // -- ADVISORY mode: flag + needs_review.
        $resultAdvisory = $this->runDivergentBestOfN('advisory');
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $resultAdvisory->verificationStatus,
            'VAL-E4-010 advisory: gate stays passed (flag does not force STATUS_FAILED)',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $resultAdvisory->completionState,
            'VAL-E4-010 advisory: completion downgraded to needs_review',
        );

        // -- HARD mode: STATUS_FAILED on the gate.
        $resultHard = $this->runDivergentBestOfN('hard');
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $resultHard->verificationStatus,
            'VAL-E4-010 hard: gate forced to STATUS_FAILED on divergence',
        );
        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $resultHard->completionState,
            'VAL-E4-010 hard: completion is NOT passed',
        );
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Run a 3-candidate divergent best-of-N under a given e4.mode and return
     * the execution result.
     */
    private function runDivergentBestOfN(string $e4Mode): mixed
    {
        $runId = 'dev-e4-010-'.$e4Mode.'-'.bin2hex(random_bytes(2));
        $storagePath = $this->tmpStorage.'-'.$e4Mode;
        mkdir($storagePath, 0o755, true);
        $storage = new ReceiptStorage($storagePath);
        $this->seedRun($storage, $runId, 'repair', 'R2');

        config()->set('atlas_dev.elevations.e4.mode', $e4Mode);
        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $workspace = sys_get_temp_dir().'/atlas-dev-e4-010-'.$e4Mode.'-'.bin2hex(random_bytes(2));
        mkdir($workspace, 0o755, true);

        try {
            $this->setupCleanWorkspaceWithFileIn('app/Foo.php', $workspace);

            // Divergent candidates.
            $this->registerFakeHermesProvider($workspace.'/app/Foo.php', identical: false);

            $commandRunner = new FakeCommandRunner;
            $this->queueGreenGate($commandRunner, count: 3);

            $container = new Container;
            $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
            $container->instance(VerificationCommandRunner::class, $commandRunner);
            $executor = new PipelineRunExecutor($container, $storage);

            return $this->executeRunWithScriptedCandidatesIn($executor, $runId, $workspace);
        } finally {
            $this->rmrf($workspace);
            $this->rmrf($storagePath);
        }
    }

    private function buildExecutor(FakeCommandRunner $commandRunner, ReceiptStorage $storage): PipelineRunExecutor
    {
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, new FakeClaudeCliGateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);

        return new PipelineRunExecutor($container, $storage);
    }

    private function executeRun(PipelineRunExecutor $executor, string $runId): mixed
    {
        return $this->executeRunWithScriptedCandidatesIn($executor, $runId, $this->tmpWorkspace);
    }

    private function executeRunWithScriptedCandidatesIn(
        PipelineRunExecutor $executor,
        string $runId,
        string $workspace,
    ): mixed {
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

    /**
     * Register a fake hermes_cli provider. When $identical is true, every
     * candidate writes the same content (agreement). When false, each writes
     * a distinct value (divergence).
     */
    private function registerFakeHermesProvider(string $target, bool $identical): void
    {
        $fakeHermes = new class($target, $identical) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly bool $identical,
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
                static $callCount = 0;
                $callCount++;
                if ($this->identical) {
                    $value = 'identical-output';
                } else {
                    $value = 'candidate-'.$callCount;
                }
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return '{$value}'; } }\n");

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
     * Queue a green gate evaluation (test command passes, php -l passes)
     * $count times.
     */
    private function queueGreenGate(FakeCommandRunner $runner, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $runner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: 50,
            ));
            $runner->queue(new VerificationCommandResult(
                command: 'php -l app/Foo.php',
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
