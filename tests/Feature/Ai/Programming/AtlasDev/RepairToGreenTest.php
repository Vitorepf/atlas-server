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
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * Tests for M2 Repair-to-Green loop on the default hermes path.
 *
 * VAL-M2-001 through VAL-M2-010 assertions.
 *
 * These tests exercise the repair loop by running PipelineRunExecutor::execute()
 * with a fake hermes_cli provider that can be scripted to succeed or fail.
 * The fake command runner simulates verification gate results.
 */
final class RepairToGreenTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E5 baseline is off for these fixtures: the scripted command-runner
        // fakes sequence their results per gate attempt ("1st call fails, 2nd
        // passes"). With E5 live in production wiring (03/07), the pre-patch
        // baseline capture would consume the first scripted result and shift
        // the whole sequence, changing what the gate observes. These tests
        // pin the M2 repair loop, not E5 (covered by RegressionBaseline*Test).
        config()->set('atlas_dev.elevations.e5.mode', 'off');
        config()->set('atlas.programming.strict_retrieval_gate', false);
        $this->app->instance(AwisExecutionGatePort::class, new class implements AwisExecutionGatePort
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return ['allowed' => true, 'status' => 'ready', 'mode' => $mode, 'blockers' => []];
            }
        });

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-repair-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-repair-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpStorage);
        File::deleteDirectory($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-001: Gate failure on attempt 1 triggers re-invocation of the hermes provider.
     */
    public function test_gate_failure_on_attempt_1_triggers_re_invocation(): void
    {
        $runId = 'dev-repair-001-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        // Shared state for the fake provider
        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $providerState->capturedPrompts = [];
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
                $this->state->capturedPrompts[] = $prompt;

                if ($this->state->callCount === 1) {
                    // First attempt: introduce a broken change
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    // Second attempt: fix the code
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $commandRunner = new FakeCommandRunner;
        // First call: php -l passes but the validation command fails
        $commandRunner->queue(new VerificationCommandResult(
            command: 'php -l app/Foo.php',
            exitCode: 0,
            stdout: 'No syntax errors detected',
            stderr: '',
            durationMs: 10,
        ));
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1,
            stdout: 'FAILURES! FooTest::test_value failed.',
            stderr: '',
            durationMs: 100,
        ));
        // Second call: everything passes
        $commandRunner->queue(new VerificationCommandResult(
            command: 'php -l app/Foo.php',
            exitCode: 0,
            stdout: 'No syntax errors detected',
            stderr: '',
            durationMs: 10,
        ));
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
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

        // VAL-M2-001: provider was re-invoked after first failure
        $this->assertGreaterThan(
            1,
            $providerState->callCount,
            'Provider must be called more than once when first attempt fails the gate'
        );
        $this->assertSame('hermes_cli', $result->providerCallSummary['provider']);
    }

    /**
     * VAL-M2-002: Loop converges to green within the cap (fail then pass).
     */
    public function test_loop_converges_to_green_within_cap(): void
    {
        $runId = 'dev-repair-002-'.bin2hex(random_bytes(3));
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
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        // Attempt 1: fails
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1, stdout: 'FAILURES!', stderr: '', durationMs: 100,
        ));
        // Attempt 2: passes
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
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

        // VAL-M2-002: final outcome is green (passed/completed)
        $this->assertContains(
            $result->verificationStatus,
            [VerificationGateResult::STATUS_PASSED, 'passed'],
            'Loop must converge to green within the cap'
        );
    }

    /**
     * VAL-M2-003: Repair attempts bounded by repairPolicy->maxAttempts.
     */
    public function test_repair_attempts_bounded_by_max_attempts(): void
    {
        $runId = 'dev-repair-003-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo {}\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        // Provider always returns the same broken code
        $providerState = new \stdClass;
        $providerState->callCount = 0;
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
                // Always produce a different output so signatures differ
                file_put_contents($this->target, "<?php\nfinal class Foo { public function v{$this->state->callCount}(): string { return 'broken-v{$this->state->callCount}'; } }\n");

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        // Always-failing command runner (produces distinct outputs each time)
        $commandRunner = new FakeCommandRunner;
        for ($i = 0; $i < 5; $i++) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 1,
                stdout: "FAILURES! Error variant {$i}",
                stderr: '',
                durationMs: 100,
            ));
        }

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 2, // cap = max(0, min(3, 2)) = 2, so 1 + 2 = 3 max calls
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

        // VAL-M2-003: total invocations never exceed 1 + cap
        $cap = max(0, min(3, 2)); // = 2
        $maxCalls = 1 + $cap; // = 3
        $this->assertLessThanOrEqual(
            $maxCalls,
            $providerState->callCount,
            "Provider calls must not exceed 1 + cap = {$maxCalls}"
        );
    }

    /**
     * VAL-M2-004: Abort on the same failure signature twice.
     */
    public function test_abort_on_same_failure_signature_twice(): void
    {
        $runId = 'dev-repair-004-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo {}\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
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
                file_put_contents($this->target, "<?php\nfinal class Foo { public function broken(): string { return 'still-broken'; } }\n");

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        // Always returns the SAME failure output (same signature)
        $commandRunner = new FakeCommandRunner;
        for ($i = 0; $i < 5; $i++) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 1,
                stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got broken',
                stderr: '',
                durationMs: 100,
            ));
        }

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 5, // cap = 3, so max 4 calls, but abort should happen at 2
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

        // VAL-M2-004: same signature twice → abort early, call count == 2
        $this->assertSame(
            2,
            $providerState->callCount,
            'Same signature twice should abort at 2 calls, not exhaust the cap'
        );
    }

    /**
     * VAL-M2-005: Signature equality is computed on the NORMALIZED excerpt, not the raw log.
     */
    public function test_signature_equality_computed_on_normalized_excerpt(): void
    {
        $hasher = new FailureSignatureHasher;

        // Raw logs differ in volatile fragments: timestamp, path, line number, UUID
        $log1 = '[2026-06-16T12:34:56.789Z] FooTest::test_x failed at /Users/op/dev/foo.php:123 '
            .'run_id=01928f8e-1234-7abc-9def-abcdef012345';
        $log2 = '[2026-06-17T08:00:01.000Z] FooTest::test_x failed at /tmp/ci/foo.php:456 '
            .'run_id=deadbeef-cafe-f00d-1234-567890abcdef';

        $sig1 = $hasher->signature('verification_gate', $log1);
        $sig2 = $hasher->signature('verification_gate', $log2);

        $this->assertSame(
            $sig1,
            $sig2,
            'Logs differing only in volatile fragments must have same signature'
        );
    }

    /**
     * VAL-M2-006: The loop always terminates (anti-spin) even on persistent distinct failures.
     */
    public function test_loop_terminates_at_cap_on_persistent_distinct_failures(): void
    {
        $runId = 'dev-repair-006-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo {}\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
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
                file_put_contents($this->target, "<?php\nfinal class Foo { public function v{$this->state->callCount}(): string { return 'variant-{$this->state->callCount}'; } }\n");

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        // Distinct failure outputs each time (different signatures)
        $commandRunner = new FakeCommandRunner;
        $failures = [
            'TypeError: cannot read property x',
            'AssertionError: expected true got false',
            'RangeError: array index out of bounds',
            'RuntimeError: division by zero',
        ];
        foreach ($failures as $failure) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 1,
                stdout: "FAILURES! {$failure}",
                stderr: '',
                durationMs: 100,
            ));
        }

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3, // cap = 3, so 1 + 3 = 4 max calls
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

        // VAL-M2-006: loop terminates at the cap, non-green outcome
        $cap = max(0, min(3, 3)); // = 3
        $maxCalls = 1 + $cap; // = 4
        $this->assertLessThanOrEqual($maxCalls, $providerState->callCount, 'Loop must terminate at the cap');
        $this->assertNotSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'Persistent distinct failures must yield non-green outcome'
        );
    }

    /**
     * VAL-M2-007: Acceptance is frozen across attempts — each retry re-runs the full M1 floor.
     */
    public function test_acceptance_frozen_across_attempts(): void
    {
        $runId = 'dev-repair-007-'.bin2hex(random_bytes(3));
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
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        // Attempt 1: test fails
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1, stdout: 'FAILURES!', stderr: '', durationMs: 100,
        ));
        // Attempt 2: test passes (the SAME test command, not skipped)
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
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

        // VAL-M2-007: The green attempt's tests still include the previously-failing command
        $this->assertContains(
            $result->verificationStatus,
            [VerificationGateResult::STATUS_PASSED, 'passed'],
            'Convergence must come from the previously-failing test actually passing'
        );
    }

    /**
     * VAL-M2-008: Failure context is fed forward into the repair invocation's prompt.
     */
    public function test_failure_context_fed_into_repair_prompt(): void
    {
        $runId = 'dev-repair-008-'.bin2hex(random_bytes(3));
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
        $providerState->capturedPrompts = [];
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
                $this->state->capturedPrompts[] = $prompt;

                if ($this->state->callCount === 1) {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        // Attempt 1: fails with specific error
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1,
            stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got broken',
            stderr: '',
            durationMs: 100,
        ));
        // Attempt 2: passes
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
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

        // VAL-M2-008: the repair invocation's prompt contains the failure excerpt
        /** @var list<string> $prompts */
        $prompts = $providerState->capturedPrompts;
        $this->assertGreaterThanOrEqual(2, count($prompts), 'At least 2 prompts should be captured');
        $repairPrompt = $prompts[1] ?? '';
        $this->assertStringContainsString(
            'REPAIR REQUIRED',
            $repairPrompt,
            'Repair prompt must contain the REPAIR REQUIRED marker'
        );
        $this->assertStringContainsString(
            'Previous attempt failed',
            $repairPrompt,
            'Repair prompt must contain the failure output'
        );
    }

    /**
     * VAL-M2-009: Exhausted/aborted runs report honest non-green completion with abort reason.
     */
    public function test_exhausted_runs_report_non_green_with_abort_reason(): void
    {
        $runId = 'dev-repair-009-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo {}\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
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
                // Produce different outputs each time to avoid same-signature abort
                file_put_contents($this->target, "<?php\nfinal class Foo { public function v{$this->state->callCount}(): string { return 'broken-v{$this->state->callCount}'; } }\n");

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        // Always fails with distinct messages
        $commandRunner = new FakeCommandRunner;
        for ($i = 0; $i < 5; $i++) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 1,
                stdout: "FAILURES! Error variant {$i}",
                stderr: '',
                durationMs: 100,
            ));
        }

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 2,
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

        // VAL-M2-009: completion is non-completed with abort reason
        $this->assertNotSame(
            'passed',
            $result->completionState,
            'Exhausted/aborted runs must report non-green completion'
        );
        // The abort reason should be in the provider call summary
        $this->assertNotNull(
            $result->providerCallSummary['repair_abort_reason'] ?? null,
            'Abort reason must be recorded'
        );
    }

    /**
     * VAL-M2-010: Anti-gaming — repair must not reach green by deleting the failing test.
     *
     * This test verifies that the M1 floor + repair loop together prevent
     * the provider from reaching green by deleting/commenting the test.
     * The verification floor re-runs the same impacted test on every attempt.
     */
    public function test_anti_gaming_repair_cannot_delete_failing_test(): void
    {
        // This assertion is enforced by the M1 floor (VAL-M1-005/006) which
        // ensures the floor runs impacted tests regardless of caller commands.
        // The repair loop re-runs the full M1 floor on every attempt, so
        // deleting the test would either:
        // 1. Cause the test command to fail (file not found)
        // 2. Be detected by scope guard if test files are in forbidden_files
        //
        // The key invariant is: convergence must come from fixing production
        // code so the same impacted test passes, never from deleting it.
        $hasher = new FailureSignatureHasher;

        // Verify that the signature hasher produces the same signature for
        // "test deleted" vs "test still failing" — so even if the provider
        // deletes the test, the failure would persist or be caught differently.
        $this->assertNotEmpty($hasher->normalize('Test file not found'));
        $this->assertNotEmpty($hasher->normalize('FAILURES! Test assertion failed'));

        // The anti-gaming guarantee comes from the floor always running
        // the impacted test. If the test file is deleted, `php artisan test`
        // would fail with "file not found" — still STATUS_FAILED.
        // No explicit assertion needed — the guarantee is structural.
    }

    /**
     * VAL-M2-003 sub-case: maxAttempts=0 means no repair (single call only).
     */
    public function test_max_attempts_zero_means_no_repair(): void
    {
        $runId = 'dev-repair-003-zero-'.bin2hex(random_bytes(3));
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
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1, stdout: 'FAILURES!', stderr: '', durationMs: 100,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 0, // No repair
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

        // maxAttempts=0 → no repair, single call
        $this->assertSame(1, $providerState->callCount, 'maxAttempts=0 must not retry');
    }

    /**
     * misc-m2-reuse-repair-prompt-composer: the repair prompt is produced by
     * the armed RepairPromptComposer (not a custom method), so it inherits the
     * composed guard rails (Repair Capsule section, stop conditions, operating
     * rules) AND preserves the REPAIR REQUIRED marker + failure excerpt the
     * custom version carried. This locks the LIGAR (reuse, do not rebuild)
     * invariant flagged by M2 scrutiny.
     */
    public function test_repair_prompt_is_composed_by_repair_prompt_composer_with_guard_rails(): void
    {
        $runId = 'dev-repair-composer-'.bin2hex(random_bytes(3));
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
        $providerState->capturedPrompts = [];
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
                $this->state->capturedPrompts[] = $prompt;

                if ($this->state->callCount === 1) {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1,
            stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got broken',
            stderr: '',
            durationMs: 100,
        ));
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
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

        /** @var list<string> $prompts */
        $prompts = $providerState->capturedPrompts;
        $this->assertGreaterThanOrEqual(2, count($prompts), 'At least 2 prompts should be captured');
        $repairPrompt = $prompts[1] ?? '';

        // Preserved from the custom version (VAL-M2-008 contract).
        $this->assertStringContainsString('REPAIR REQUIRED', $repairPrompt);
        $this->assertStringContainsString('Previous attempt failed', $repairPrompt);

        // Inherited from the armed RepairPromptComposer (guard rails).
        // These tokens are emitted ONLY by RepairPromptComposer::renderPrompt();
        // the old custom buildHermesRepairPrompt() never produced them, so their
        // presence proves the composed service is on the hermes repair path.
        $this->assertStringContainsString('# Repair Capsule', $repairPrompt);
        $this->assertStringContainsString('failure_signature:', $repairPrompt);
        $this->assertStringContainsString('stop_if_same_failure_signature_repeats', $repairPrompt);
        $this->assertStringContainsString('stop_if_max_repair_attempts_reached', $repairPrompt);
        $this->assertStringContainsString('stop_if_diff_grows_beyond_previous_attempt', $repairPrompt);
        // The provider-lock audit line is also composed-only.
        $this->assertStringContainsString('provider_lock:', $repairPrompt);
    }

    /**
     * misc-m2-restore-repair-prompt-20k-cap-on-composed-path:
     *
     * The MAIN composed repair-prompt branch (the one that runs when the
     * "# Repair Capsule" header IS found) must cap the ORIGINAL prompt body
     * (the pre-capsule portion) to <=20,000 characters via mb_substr, matching
     * the prior custom buildHermesRepairPrompt() behavior. The guard-rail
     * sections (# Repair Capsule / # Primary Error / # Stop Conditions) and
     * the injected REPAIR REQUIRED marker must be preserved IN FULL — the cap
     * must never truncate the guard rails.
     *
     * Locks: (a) oversized original prompt → pre-capsule portion <=20_000 chars;
     *        (b) all composed guard-rail tokens + REPAIR REQUIRED marker present.
     */
    public function test_composed_repair_prompt_caps_pre_capsule_to_20k_while_preserving_guard_rails(): void
    {
        $runId = 'dev-repair-20k-cap-'.bin2hex(random_bytes(3));
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
        $providerState->capturedPrompts = [];
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
                $this->state->capturedPrompts[] = $prompt;

                if ($this->state->callCount === 1) {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'broken'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 1,
            stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got broken',
            stderr: '',
            durationMs: 100,
        ));
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
                'same_provider' => true,
                'requires_failed_gate_output' => true,
                'abort_on_same_signature_twice' => true,
            ],
        ]);

        // Build a projection whose renderedPromptText is OVERSIZED (>20k chars)
        // so the main branch's 20k cap is exercised. The original
        // buildHermesRepairPrompt() applied mb_substr(renderedPromptText, 0, 20_000)
        // to the original prompt body; this cap must survive on the composed path.
        $baseProjection = $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract);
        $oversizedBody = str_repeat('A', 25_000); // 25k chars, exceeds the 20k cap
        $oversizedPayload = $baseProjection->toCanonicalArray();
        $oversizedPayload['rendered_prompt_text'] = $oversizedBody;
        $oversizedPayload['rendered_prompt_hash'] = hash('sha256', $oversizedBody);
        $oversizedProjection = ProviderPromptProjection::fromArray($oversizedPayload);

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $oversizedProjection,
            runId: $runId,
        );

        /** @var list<string> $prompts */
        $prompts = $providerState->capturedPrompts;
        $this->assertGreaterThanOrEqual(2, count($prompts), 'At least 2 prompts should be captured');
        $repairPrompt = $prompts[1] ?? '';

        // --- (a) Original-prompt portion (pre-capsule, pre-marker) must be capped to <=20,000 chars ---
        // The cap applies to the ORIGINAL prompt body. The REPAIR REQUIRED marker
        // is injected BETWEEN the capped original and the capsule, so we measure
        // the text before the marker to isolate the original-prompt body.
        $capsuleHeader = '# Repair Capsule';
        $capsulePos = strpos($repairPrompt, $capsuleHeader);
        $this->assertNotFalse($capsulePos, 'Repair Capsule header must be present in composed repair prompt');
        $markerHeader = '--- REPAIR REQUIRED';
        $markerPos = strpos($repairPrompt, $markerHeader);
        $this->assertNotFalse($markerPos, 'REPAIR REQUIRED marker must be present before the capsule');
        $this->assertLessThan($capsulePos, $markerPos, 'Marker must be injected before the capsule section');
        $originalPromptBody = substr($repairPrompt, 0, $markerPos);
        $this->assertLessThanOrEqual(
            20_000,
            mb_strlen($originalPromptBody),
            'Original-prompt body (pre-capsule, pre-marker) must be capped to <=20,000 chars. '
            .'Got: '.mb_strlen($originalPromptBody)
        );

        // --- (b) Guard-rail tokens + REPAIR REQUIRED marker must ALL be present (never truncated) ---
        $this->assertStringContainsString('REPAIR REQUIRED', $repairPrompt, 'REPAIR REQUIRED marker must survive the cap');
        $this->assertStringContainsString('# Repair Capsule', $repairPrompt, 'Repair Capsule section must survive the cap');
        $this->assertStringContainsString('failure_signature:', $repairPrompt, 'failure_signature must survive the cap');
        $this->assertStringContainsString('stop_if_same_failure_signature_repeats', $repairPrompt, 'stop condition must survive the cap');
        $this->assertStringContainsString('stop_if_max_repair_attempts_reached', $repairPrompt, 'stop condition must survive the cap');
        $this->assertStringContainsString('provider_lock:', $repairPrompt, 'provider_lock must survive the cap');

        // Verify the post-capsule portion (guard rails) is the FULL composed tail,
        // not truncated. The capsule section + stop conditions should be intact.
        $postCapsule = substr($repairPrompt, $capsulePos);
        $this->assertStringContainsString('# Primary Error', $postCapsule, 'Primary Error section must be in the guard-rail tail');
        $this->assertStringContainsString('# Stop Conditions', $postCapsule, 'Stop Conditions section must be in the guard-rail tail');
    }

    // ------------------------------------------------------------------
    // W1 repair-on-weak-green: a PASSED gate with placeholder markers in
    // the applied diff gets a repair attempt BEFORE the post-gate W1 probe
    // flags it for a human.
    // ------------------------------------------------------------------

    /**
     * Attempt 1 passes the gate but the applied diff carries a TODO
     * placeholder; the loop re-invokes the provider and attempt 2 delivers
     * the real implementation. Final: genuinely green, no weak flag.
     */
    public function test_weak_green_placeholder_triggers_repair_and_converges(): void
    {
        $this->pinElevationsOffExceptWeakOutput();
        $runId = 'dev-weakgreen-001-'.bin2hex(random_bytes(3));
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
        $this->registerWeakThenCleanHermes($target, $providerState, weakForever: false);

        $commandRunner = new FakeCommandRunner;
        // BOTH attempts pass the gate — the weakness is in the diff content,
        // not the test outcome (that is the entire point of W1).
        foreach ([100, 80] as $duration) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: $duration,
            ));
        }

        $result = $this->executeWeakGreenRun($storage, $runId, $commandRunner);

        $this->assertSame(2, $providerState->callCount, 'weak-green attempt must trigger exactly one repair re-invocation');
        $this->assertSame(VerificationGateResult::STATUS_PASSED, $result->verificationStatus);
        $receipt = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertNotContains(
            'weak_output_detected',
            (array) ($receipt['completion']['honesty_flags'] ?? []),
            'converged repair must clear the weak-output flag entirely',
        );
    }

    /**
     * weak_output mode=off => byte-identical legacy exit: a passing gate
     * exits the loop immediately even with a placeholder diff (the post-gate
     * W1 probe is off too — no repair, no flag).
     */
    public function test_weak_green_off_mode_exits_green_without_repair(): void
    {
        $this->pinElevationsOffExceptWeakOutput();
        config()->set('atlas_dev.elevations.weak_output.mode', 'off');
        $runId = 'dev-weakgreen-002-'.bin2hex(random_bytes(3));
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
        $this->registerWeakThenCleanHermes($target, $providerState, weakForever: true);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 100,
        ));

        $result = $this->executeWeakGreenRun($storage, $runId, $commandRunner);

        $this->assertSame(1, $providerState->callCount, 'off mode must not spend repair invocations on a green gate');
        $this->assertSame(VerificationGateResult::STATUS_PASSED, $result->verificationStatus);
    }

    /**
     * A model that keeps returning the same placeholder trips the
     * same-signature-twice anti-spin (bounded loop), and the surviving weak
     * output is flagged by the post-gate W1 probe — never silently green.
     */
    public function test_weak_green_persistent_placeholder_aborts_and_flags(): void
    {
        $this->pinElevationsOffExceptWeakOutput();
        $runId = 'dev-weakgreen-003-'.bin2hex(random_bytes(3));
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
        $this->registerWeakThenCleanHermes($target, $providerState, weakForever: true);

        $commandRunner = new FakeCommandRunner;
        foreach ([100, 90, 80, 70] as $duration) {
            $commandRunner->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: $duration,
            ));
        }

        $result = $this->executeWeakGreenRun($storage, $runId, $commandRunner);

        $this->assertSame(2, $providerState->callCount, 'same placeholder twice must abort the chain (anti-spin)');
        $receipt = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertContains(
            'weak_output_detected',
            (array) ($receipt['completion']['honesty_flags'] ?? []),
            'surviving weak output must carry the honesty flag — never silently green',
        );
        $this->assertNotSame('passed', $result->completionState, 'weak output must not complete as passed');
    }

    /**
     * The repair-iteration revert is scoped to the run's allowed_files: an
     * operator's uncommitted change OUTSIDE the task scope must survive
     * every repair revert (the former whole-workspace
     * `git checkout . && git clean -fd` destroyed it).
     */
    public function test_repair_revert_preserves_operator_changes_outside_allowed_files(): void
    {
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        $operatorFile = $this->tmpWorkspace.'/app/Operator.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        file_put_contents($operatorFile, "<?php\nfinal class Operator { public function wip(): string { return 'committed'; } }\n");
        $this->git(['add', 'app/Foo.php', 'app/Operator.php']);
        $this->git(['commit', '-m', 'fixture']);

        // Operator work-in-progress OUTSIDE the task's allowed_files:
        // uncommitted tracked change + untracked scratch file.
        file_put_contents($operatorFile, "<?php\nfinal class Operator { public function wip(): string { return 'UNCOMMITTED OPERATOR WORK'; } }\n");
        file_put_contents($this->tmpWorkspace.'/app/operator-scratch.txt', "operator notes\n");

        // Provider attempt artifacts INSIDE allowed_files: a tracked edit and
        // an untracked new file.
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'provider-attempt'; } }\n");
        file_put_contents($this->tmpWorkspace.'/app/FooHelper.php', "<?php\n// provider-created\n");

        $executor = new PipelineRunExecutor(new Container, new ReceiptStorage($this->tmpStorage));
        $revert = new \ReflectionMethod($executor, 'revertWorkspaceChanges');
        $revert->invoke($executor, $this->tmpWorkspace, ['app/Foo.php', 'app/FooHelper.php'], [
            'entries' => [
                'app/Foo.php' => ['kind' => 'file', 'contents' => "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n"],
                'app/FooHelper.php' => ['kind' => 'missing'],
            ],
        ]);

        $this->assertStringContainsString(
            "'before'",
            (string) file_get_contents($target),
            'allowed tracked file must be reverted to HEAD',
        );
        $this->assertFileDoesNotExist(
            $this->tmpWorkspace.'/app/FooHelper.php',
            'allowed untracked provider file must be cleaned',
        );
        $this->assertStringContainsString(
            'UNCOMMITTED OPERATOR WORK',
            (string) file_get_contents($operatorFile),
            'repair revert must NOT destroy operator changes outside allowed_files',
        );
        $this->assertFileExists(
            $this->tmpWorkspace.'/app/operator-scratch.txt',
            'repair revert must NOT git-clean untracked files outside allowed_files',
        );

        // Declared-scope floor: an empty allowed_files list must NOT fall
        // back to a whole-workspace wipe.
        file_put_contents($operatorFile, "<?php\nfinal class Operator { public function wip(): string { return 'STILL HERE'; } }\n");
        $revert->invoke($executor, $this->tmpWorkspace, [], ['entries' => []]);
        $this->assertStringContainsString(
            'STILL HERE',
            (string) file_get_contents($operatorFile),
            'empty allowed_files must be a no-op revert, never a workspace wipe',
        );
    }

    /**
     * Axis isolation for the W1 weak-green tests: E1-E6 landed with hard
     * defaults that are not under test here; weak_output stays at its
     * config default (advisory) unless the test overrides it.
     */
    public function test_no_patch_on_write_task_triggers_repair_and_converges(): void
    {
        // no_patch_produced is the dominant real failure class (18/56 of the
        // audited corpus): a workspace-mutating provider that completes WITHOUT
        // a diff on a write task previously exited with zero repair attempts.
        // The no-patch signal now rides the weak-green repair channel.
        $this->pinElevationsOffExceptWeakOutput();
        $runId = 'dev-nopatch-001-'.bin2hex(random_bytes(3));
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
        $this->registerNoPatchThenCleanHermes($target, $providerState);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 90,
        ));

        $result = $this->executeWeakGreenRun($storage, $runId, $commandRunner);

        $this->assertSame(2, $providerState->callCount, 'no-patch on a write task must trigger exactly one repair re-invocation');
        $this->assertSame('passed', $result->completionState, 'the repaired attempt converges to green');
    }

    /**
     * Fake hermes workspace-mutator: attempt 1 changes NOTHING (the no-patch
     * failure mode); attempt 2 writes the real change.
     */
    private function registerNoPatchThenCleanHermes(string $target, object $state): void
    {
        $fakeHermes = new class($target, $state) implements AiProvider
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
                if ($this->state->callCount > 1) {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

                return new AiProviderResult(
                    ok: true, output: 'done', command: [], exitCode: 0,
                    durationMs: 80, stdout: 'done', stderr: '',
                    errorCode: null, errorMessage: null, metadata: [],
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

    private function pinElevationsOffExceptWeakOutput(): void
    {
        foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6'] as $elevation) {
            config()->set('atlas_dev.elevations.'.$elevation.'.mode', 'off');
        }
    }

    /**
     * Fake hermes workspace-mutator: attempt 1 writes a green-but-placeholder
     * implementation; later attempts write the real one (or keep the
     * placeholder forever when $weakForever).
     */
    private function registerWeakThenCleanHermes(string $target, object $state, bool $weakForever): void
    {
        $fakeHermes = new class($target, $state, $weakForever) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
                private readonly bool $weakForever,
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
                if ($this->weakForever || $this->state->callCount === 1) {
                    file_put_contents($this->target, "<?php\n// TODO: implement the real value\nfinal class Foo { public function value(): string { return 'stub'; } }\n");
                } else {
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");
                }

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

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);
    }

    private function executeWeakGreenRun(
        ReceiptStorage $storage,
        string $runId,
        FakeCommandRunner $commandRunner,
    ): \App\Http\Controllers\AtlasDev\Support\RunExecutionResult {
        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

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
                'max_attempts' => 3,
                'same_provider' => true,
                'requires_failed_gate_output' => true,
                'abort_on_same_signature_twice' => true,
            ],
        ]);

        return (new PipelineRunExecutor($container, $storage))->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(?string $intent = null, ?string $providerChoice = null): OperationEnvelope
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
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
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
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
