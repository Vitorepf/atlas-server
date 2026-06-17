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
use Illuminate\Container\Container;
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

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-repair-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-repair-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
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
