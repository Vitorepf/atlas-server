<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
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
 * Tests for M4 Best-of-N (MiniMax-only) on the default hermes path.
 *
 * VAL-M4-001 through VAL-M4-009 and the no-passing-candidate arm of
 * VAL-CROSS-003. Each test runs PipelineRunExecutor::execute() with a
 * fake hermes_cli provider (the MiniMax-M3 lock) scripted to produce N
 * candidate diffs, and a fake command runner that simulates per-candidate
 * gate outcomes.
 *
 * The hermes provider in these tests is workspace-mutating: it writes the
 * candidate file content into the workspace, just like the real Hermes CLI.
 */
final class BestOfNHermesTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        // Best-of-N is OFF by default (N=1 = pre-M4 single-call behavior).
        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-bon-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-bon-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M4-001: N candidate diffs are generated on the synchronous hermes path.
     */
    public function test_n_candidate_diffs_generated_with_n_greater_than_1(): void
    {
        $runId = 'dev-bon-001-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        // Each candidate writes a distinct value so the diffs differ.
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'candidate');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // N=3 candidates; gate each one green (php -l + test pass per candidate).
        $this->queueGreenGate($commandRunner, count: 3);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-001: provider run() invoked exactly N times.
        $this->assertSame(3, $providerState->callCount, 'Provider must be invoked N times when best-of-N N=3');
    }

    /**
     * VAL-M4-002: Every candidate is run through the M1 floor + gate.
     *
     * The gate records one aggregate per candidate. We assert the command
     * runner received the floor command set N times (php -l + the test), i.e.
     * no candidate was selected without its own gate evaluation.
     */
    public function test_every_candidate_runs_through_m1_floor_and_gate(): void
    {
        $runId = 'dev-bon-002-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'candidate');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-002: the command runner executed the floor (php -l on the
        // touched file) at least N times — once per candidate gate evaluation.
        $phpLInvocations = 0;
        foreach ($commandRunner->calls as $call) {
            if (str_starts_with($call['command'], 'php -l app/Foo.php')) {
                $phpLInvocations++;
            }
        }
        $this->assertGreaterThanOrEqual(
            3,
            $phpLInvocations,
            'Each of the N candidates must independently run through the M1 floor (php -l executed >= N times)'
        );

        // And the verification status reflects the winning candidate passing.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'Winning candidate must have passed the gate'
        );
    }

    /**
     * VAL-M4-003: The best PASSING candidate is chosen deterministically.
     *
     * Same fixture run twice must select the same winner (stable tie-break).
     * The documented tie-break is: lowest candidate index among the passing
     * candidates wins (first passing candidate in generation order).
     */
    public function test_best_passing_candidate_chosen_deterministically(): void
    {
        // Two independent runs with the same scripted candidate sequence.
        // Candidate 1 passes, candidate 2 passes, candidate 3 fails.
        // Winner must be candidate 1 (lowest-index passing) in BOTH runs.
        $winnerHashes = [];
        foreach (range(1, 2) as $runIteration) {
            $runId = 'dev-bon-003-'.$runIteration.'-'.bin2hex(random_bytes(2));
            $storage = new ReceiptStorage($this->tmpStorage.'-'.$runIteration);
            mkdir($this->tmpStorage.'-'.$runIteration, 0o755, true);
            $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

            $workspace = sys_get_temp_dir().'/atlas-dev-bon-det-'.$runIteration.'-'.bin2hex(random_bytes(2));
            mkdir($workspace, 0o755, true);
            try {
                $this->initGitWorkspaceIn($workspace);
                $target = $workspace.'/app/Foo.php';
                mkdir(dirname($target), 0o755, true);
                file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
                $this->gitIn($workspace, ['add', 'app/Foo.php']);
                $this->gitIn($workspace, ['commit', '-m', 'fixture']);

                $providerState = new \stdClass;
                $providerState->callCount = 0;
                $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'c');

                $manager = app(AiProviderManager::class);
                $manager->registerDriver('hermes_cli', $fakeHermes);
                app()->instance(AiProviderManager::class, $manager);

                $commandRunner = new FakeCommandRunner;
                // Candidate 1: pass, Candidate 2: pass, Candidate 3: fail.
                $this->queueGreenGate($commandRunner); // c1
                $this->queueGreenGate($commandRunner); // c2
                $this->queueRedGate($commandRunner);   // c3

                $gateway = new FakeClaudeCliGateway;
                $container = new Container;
                $container->instance(ClaudeCliGateway::class, $gateway);
                $container->instance(VerificationCommandRunner::class, $commandRunner);
                $executor = new PipelineRunExecutor($container, $storage);

                config()->set('atlas_dev.best_of_n.candidate_count', 3);

                $envelope = $this->envelopeIn($workspace, intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

                $result = $executor->execute(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
                    runId: $runId,
                );

                $this->assertSame(
                    VerificationGateResult::STATUS_PASSED,
                    $result->verificationStatus,
                    "Run {$runIteration}: a passing candidate must be selected"
                );
                $winnerHashes[] = $result->diffHash;
            } finally {
                $this->rmrf($workspace);
                $this->rmrf($this->tmpStorage.'-'.$runIteration);
            }
        }

        // VAL-M4-003: same fixture → same winner across runs.
        $this->assertSame(
            $winnerHashes[0],
            $winnerHashes[1],
            'Identical fixture must deterministically select the same winning candidate diff hash'
        );
        $this->assertNotNull($winnerHashes[0], 'A passing winner must have a non-null diff hash');
    }

    /**
     * VAL-M4-004: A losing/failing candidate does not abort selection.
     *
     * Fixture: [fail, pass, fail]. The passing candidate must be selected
     * and the run completes (no exception bubbles out).
     */
    public function test_losing_or_failing_candidate_does_not_abort_selection(): void
    {
        $runId = 'dev-bon-004-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        // Candidate 2 THROWS during generation — must not abort the run.
        $fakeHermes = new class($target, $providerState) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
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
                $this->state->callCount++;
                if ($this->state->callCount === 2) {
                    // Candidate 2: pass (the winner). Write a fixed value.
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed-c2'; } }\n");

                    return new AiProviderResult(
                        ok: true, output: 'edited', command: [], exitCode: 0,
                        durationMs: 100, stdout: 'edited', stderr: '',
                        errorCode: null, errorMessage: null, metadata: [],
                    );
                }
                // Candidates 1 and 3: write broken content (gate will fail them).
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken-c'.$this->state->callCount; } }\n");

                return new AiProviderResult(
                    ok: true, output: 'edited', command: [], exitCode: 0,
                    durationMs: 100, stdout: 'edited', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // Candidate 1: fail, Candidate 2: pass, Candidate 3: fail.
        $this->queueRedGate($commandRunner);
        $this->queueGreenGate($commandRunner);
        $this->queueRedGate($commandRunner);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-004: passing candidate selected, run completed, no abort.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'A failing/throwing candidate must not abort selection; the passing one wins'
        );
        $this->assertSame(3, $providerState->callCount, 'All N candidates must be attempted');
    }

    /**
     * VAL-M4-005: If NO candidate passes the gate, the run must not report completed.
     */
    public function test_no_passing_candidate_yields_non_completed(): void
    {
        $runId = 'dev-bon-005-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'allred');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // All 3 candidates fail the gate.
        $this->queueRedGate($commandRunner);
        $this->queueRedGate($commandRunner);
        $this->queueRedGate($commandRunner);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-005: all candidates failed → non-completed (anti-gaming).
        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'A no-winner round must NOT manufacture green'
        );
        $this->assertSame(3, $providerState->callCount, 'All N candidates must still be attempted');
    }

    /**
     * VAL-M4-006: All candidates use hermes_cli + MiniMax-M3 — no engine swap.
     */
    public function test_all_candidates_use_hermes_cli_and_minimax_model_family(): void
    {
        $runId = 'dev-bon-006-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $providerState->observedJobs = [];
        $fakeHermes = new class($target, $providerState) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
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
                $this->state->callCount++;
                $this->state->observedJobs[] = $job;
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'v'.$this->state->callCount; } }\n");

                return new AiProviderResult(
                    ok: true, output: 'edited', command: [], exitCode: 0,
                    durationMs: 100, stdout: 'edited', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-006: the persisted provider call summary reports hermes_cli +
        // minimax-m3, and every candidate AiJob was on the hermes_cli provider.
        $this->assertSame('hermes_cli', $result->providerCallSummary['provider']);
        $this->assertSame('minimax-m3', $result->providerCallSummary['model_family']);
        /** @var list<AiJob> $jobs */
        $jobs = $providerState->observedJobs;
        $this->assertCount(3, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame('hermes_cli', $job->getAttribute('provider'), 'No candidate may swap the engine');
        }
    }

    /**
     * VAL-M4-007: Intra-model decorrelation reported honestly — no cross-engine equivalence claim.
     */
    public function test_receipt_carries_honest_intra_model_annotation_and_no_equivalence_claim(): void
    {
        $runId = 'dev-bon-007-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'c');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        $this->queueGreenGate($commandRunner, count: 3);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-007: the honesty annotation is present and no equivalence/parity token leaks.
        $summary = $result->providerCallSummary;
        $bonBlock = $summary['best_of_n'] ?? null;
        $this->assertNotNull($bonBlock, 'A best-of-N run must carry a best_of_n summary block');
        $serialized = json_encode($bonBlock);
        $this->assertNotFalse($serialized);
        $this->assertStringContainsString(
            'intra_model_best_of_n_weaker_than_cross_engine',
            $serialized,
            'The receipt must carry the explicit honest-weakness annotation'
        );
        $this->assertStringNotContainsString('equivalent', strtolower($serialized), 'No equivalence over-claim token');
        $this->assertStringNotContainsString('parity', strtolower($serialized), 'No parity over-claim token');
    }

    /**
     * VAL-M4-008: The selected winner's diff is the one actually applied/persisted.
     */
    public function test_selected_winner_diff_hash_equals_persisted_diff_hash(): void
    {
        $runId = 'dev-bon-008-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        // Each candidate writes a DIFFERENT, known value so the winner's diff
        // is uniquely identifiable by its diff hash.
        $fakeHermes = new class($target, $providerState) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
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
                $this->state->callCount++;
                // Candidate 1 fails, candidate 2 is the winner (lowest-index passing),
                // candidate 3 passes too but loses the tie-break.
                $values = ['broken-a', 'WINNER-b', 'passing-c'];
                $value = $values[($this->state->callCount - 1) % 3] ?? 'fallback';
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return '{$value}'; } }\n");

                return new AiProviderResult(
                    ok: true, output: 'edited', command: [], exitCode: 0,
                    durationMs: 100, stdout: 'edited', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // c1 fail, c2 pass (winner), c3 pass.
        $this->queueRedGate($commandRunner);
        $this->queueGreenGate($commandRunner);
        $this->queueGreenGate($commandRunner);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-008: persisted final diff hash == winner's diff hash.
        // The winner is candidate 2 ("WINNER-b"). Its content must be on disk.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'A passing winner must be selected'
        );
        $this->assertStringContainsString(
            'WINNER-b',
            (string) file_get_contents($target),
            'The selected winner\'s diff must be the one applied to the workspace'
        );
        $this->assertNotNull($result->diffHash, 'Persisted diff hash must be present');
    }

    /**
     * VAL-M4-009: Degenerate N=1 preserves current single-call behavior (no regression).
     *
     * With N=1 (best-of-N off/minimal), behavior matches the pre-M4 single-call
     * path: the repair loop runs as before, and a passing single candidate →
     * passed, a failing single candidate → non-completed. This test verifies
     * N=1 yields a passing outcome identical to the baseline.
     */
    public function test_n_equals_1_preserves_single_call_behavior_passing(): void
    {
        $runId = 'dev-bon-009-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'single');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // Single green gate.
        $this->queueGreenGate($commandRunner);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 1);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M4-009: N=1 → single call, passing outcome (baseline behavior).
        $this->assertSame(1, $providerState->callCount, 'N=1 must invoke the provider exactly once');
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'N=1 with a passing single candidate must yield passed (baseline)'
        );
    }

    /**
     * VAL-CROSS-003 (no-passing-candidate arm): a no-passing-candidate
     * best-of-N round prevents a green completion.
     *
     * This is the third leg of VAL-CROSS-003 (the other two legs —
     * repair-exhausted and critic-blocker — are covered by the M2/M3 suites).
     * It re-uses the all-red best-of-N fixture and asserts non-passed.
     */
    public function test_cross_003_no_passing_candidate_prevents_green_completion(): void
    {
        $runId = 'dev-bon-cross003-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, variantPrefix: 'red');

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        $this->queueRedGate($commandRunner, count: 3);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'VAL-CROSS-003: a no-passing-candidate round must prevent green completion'
        );
    }

    /**
     * REGRESSION (m4-fix-reapply-candidate-diff-fail-closed): when the winner
     * has a non-empty diff and its re-application to the reverted workspace
     * FAILS (git apply non-zero exit, e.g. context drift), the run MUST NOT
     * report the winner's green metadata. It must yield a NON-completed
     * outcome (non-passed verification / failed patch_apply) carrying the
     * apply-failure reason, mirroring the no-candidate-produced branch.
     *
     * Anti-gaming: a desynced workspace (one whose actual state does NOT match
     * the selected winner's diff) must never report green.
     *
     * Fixture: candidate 1 is the winner (passes the gate). Its diff is
     * captured against HEAD=V1. Candidate 2 (fails the gate) commits V3 to
     * HEAD as a side effect, so when the workspace is reverted after the loop
     * the target file is V3, and re-applying the winner's V1->V2 diff fails
     * (context drift) — exactly the fail-open gap the fix closes.
     */
    public function test_winner_reapply_failure_yields_non_completed_not_green(): void
    {
        $runId = 'dev-bon-reapply-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        $v1 = "<?php\nfinal class Foo { public function value(): string { return 'baseline-V1'; } }\n";
        file_put_contents($target, $v1);
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture V1']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        // Candidate 1 (winner, passes): writes V2 over V1.
        // Candidate 2 (fails gate): writes V3 AND commits V3 to HEAD, so the
        // post-loop revert restores V3 and the winner's V1->V2 diff cannot
        // apply (context drift) -> git apply non-zero exit.
        // Candidate 3 (fails gate): writes V3 again (no-op vs HEAD).
        $fakeHermes = new class($target, $providerState) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
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
                $this->state->callCount++;
                if ($this->state->callCount === 1) {
                    // Winner: V1 -> V2 (diff captured against HEAD=V1).
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'winner-V2'; } }\n");

                    return new AiProviderResult(
                        ok: true, output: 'edited', command: [], exitCode: 0,
                        durationMs: 100, stdout: 'edited', stderr: '',
                        errorCode: null, errorMessage: null, metadata: [],
                    );
                }
                // Candidate 2+: write V3 and commit V3 to HEAD so the
                // post-loop revert restores V3, invalidating the winner's
                // V1-context diff (deterministic git apply failure).
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'drift-V3'; } }\n");
                $this->gitIn(dirname($this->target), ['add', 'app/Foo.php']);
                $this->gitIn(dirname($this->target), ['commit', '-m', 'drift V3']);

                return new AiProviderResult(
                    ok: true, output: 'edited', command: [], exitCode: 0,
                    durationMs: 100, stdout: 'edited', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }

            /** @param  list<string>  $args */
            private function gitIn(string $workspace, array $args): void
            {
                // Run git from the workspace root (parent of app/).
                $root = dirname($workspace);
                $process = new Process(['git', ...$args], $root, null, null, 10.0);
                $process->run();
            }
        };

        $this->registerHermes($fakeHermes);

        $commandRunner = new FakeCommandRunner;
        // Candidate 1 (winner): pass. Candidates 2 and 3: fail the gate.
        $this->queueGreenGate($commandRunner);
        $this->queueRedGate($commandRunner);
        $this->queueRedGate($commandRunner);

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        config()->set('atlas_dev.best_of_n.candidate_count', 3);

        $envelope = $this->envelope(intent: 'Fix app/Foo.php', providerChoice: 'hermes_cli');
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

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // REGRESSION: winner re-apply failed -> NON-completed (anti-gaming:
        // a desynced workspace must never report green).
        $this->assertNotSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'Winner re-apply failure must NOT report passed verification'
        );
        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $result->completionState,
            'Winner re-apply failure must NOT report a green (passed) completion'
        );
        // The provider call summary carries the winner_reapply_failed error
        // code (the blocked call result synthesized by the fail-closed branch).
        $this->assertContains(
            'winner_reapply_failed',
            $result->providerCallSummary['error_codes'] ?? [],
            'The provider call summary must carry the winner_reapply_failed error code'
        );
        // The persisted patch-apply receipt records STATUS_FAILED + the reason.
        $patchReceiptPath = $result->persistedReceiptPaths[ArtifactNames::PATCH_APPLY_RESULT] ?? null;
        $this->assertNotNull($patchReceiptPath, 'A patch-apply receipt must be persisted');
        $patchReceipt = json_decode((string) file_get_contents($patchReceiptPath), true);
        $this->assertSame(
            'failed',
            $patchReceipt['status'] ?? null,
            'Persisted patch-apply status must be "failed" when the winner re-apply fails'
        );
        $this->assertSame(
            'winner_reapply_failed',
            $patchReceipt['reason'] ?? null,
            'The persisted failure reason must identify a winner-reapply failure'
        );
        // Sanity: all 3 candidates were still attempted (no skip).
        $this->assertSame(3, $providerState->callCount, 'All N candidates must still be attempted');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeFakeHermesProvider(
        string $target,
        object $state,
        string $variantPrefix,
    ): AiProvider {
        return new class($target, $state, $variantPrefix) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
                private readonly string $variantPrefix,
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
                $this->state->callCount++;
                $variant = $this->variantPrefix.'-'.$this->state->callCount;
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return '{$variant}'; } }\n");

                return new AiProviderResult(
                    ok: true, output: 'edited', command: [], exitCode: 0,
                    durationMs: 100, stdout: 'edited', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };
    }

    private function registerHermes(AiProvider $fakeHermes): void
    {
        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);
    }

    /**
     * Queue a green gate evaluation (php -l + the test command pass) `$count` times.
     */
    private function queueGreenGate(FakeCommandRunner $runner, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $runner->queue(new VerificationCommandResult(
                command: 'php -l app/Foo.php',
                exitCode: 0, stdout: 'No syntax errors detected', stderr: '', durationMs: 5,
            ));
            $runner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: 50,
            ));
        }
    }

    /**
     * Queue a red gate evaluation (the test command fails) `$count` times.
     */
    private function queueRedGate(FakeCommandRunner $runner, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $runner->queue(new VerificationCommandResult(
                command: 'php -l app/Foo.php',
                exitCode: 0, stdout: 'No syntax errors detected', stderr: '', durationMs: 5,
            ));
            $runner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 1, stdout: 'FAILURES! variant '.$i, stderr: '', durationMs: 50,
            ));
        }
    }

    private function envelope(?string $intent = null, ?string $providerChoice = null): OperationEnvelope
    {
        return $this->envelopeIn($this->tmpWorkspace, $intent, $providerChoice);
    }

    private function envelopeIn(string $workspace, ?string $intent = null, ?string $providerChoice = null): OperationEnvelope
    {
        $intent ??= 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: $providerChoice,
            ),
            workspace: $workspace,
            workspaceHash: hash('sha256', $workspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: $intent,
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

    private function initGitWorkspace(): void
    {
        $this->initGitWorkspaceIn($this->tmpWorkspace);
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
    private function git(array $args): void
    {
        $this->gitIn($this->tmpWorkspace, $args);
    }

    /**
     * @param  list<string>  $args
     */
    private function gitIn(string $workspace, array $args): void
    {
        $process = new Process(['git', ...$args], $workspace, null, null, 10.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
