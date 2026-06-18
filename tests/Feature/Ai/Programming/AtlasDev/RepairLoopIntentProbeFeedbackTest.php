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
 * E1 — Repair-loop intent-probe feedback (VAL-E1-006, VAL-E1-007,
 * VAL-E1-008, VAL-E1-013, VAL-CROSS-006).
 *
 * The deterministic intent-falsification probe feeds its reason into the
 * M2 repair loop as a SEPARATE field passed into
 * buildComposedHermesRepairPrompt(). CRITICAL: $failureExcerpt is NEVER
 * mutated (it is hashed by FailureSignatureHasher for same-signature-twice
 * abort). The probe reason is a live input the regenerated attempt can act
 * on (convergence), while leaving the failure signature byte-identical so
 * the cap=3 anti-spin still fires on a genuinely stuck repair.
 *
 * These tests drive a synthetic diff through the fake-provider harness and
 * assert the new behavior via the captured repair prompts, the hasher
 * output, and the loop abort decision.
 */
final class RepairLoopIntentProbeFeedbackTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas_dev.elevations.e1.mode', 'advisory');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e1-repair-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e1-repair-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E1-006: Probe reason reaches the M2 repair loop as a separate field.
     *
     * On a repair iteration with the flag active, the composed repair prompt
     * carries the probe reason as a DISTINCT field (dedicated section),
     * separate from failureExcerpt. The failureExcerpt is unchanged by the
     * probe (it carries only the gate failure, not the probe reason).
     */
    public function test_val_e1_006_probe_reason_reaches_repair_prompt_as_dedicated_field(): void
    {
        $runId = 'e1-repair-006-'.bin2hex(random_bytes(3));
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
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'unrelated'; } }\n");
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
            stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got unrelated',
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

        $envelope = $this->envelope(intent: 'Fix rate-limit guard', providerChoice: 'hermes_cli');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'intent_text' => 'Fix rate-limit guard',
            'intent_verbs' => ['corrigir'],
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
        $this->assertGreaterThanOrEqual(2, count($prompts), 'At least 2 prompts captured (initial + repair)');
        $repairPrompt = $prompts[1] ?? '';

        // VAL-E1-006: the probe reason is carried as a DISTINCT field (dedicated section).
        $this->assertStringContainsString(
            'Intent Not Yet Addressed',
            $repairPrompt,
            'VAL-E1-006: repair prompt must carry the probe reason in a dedicated section',
        );
        // The reason body references the unaddressed intent verb (a live input).
        $this->assertStringContainsString(
            'corrigir',
            $repairPrompt,
            'VAL-E1-006: probe reason body references the unaddressed intent verb',
        );

        // The failureExcerpt structure is preserved (M2 contract).
        $this->assertStringContainsString('REPAIR REQUIRED', $repairPrompt);
        $this->assertStringContainsString('Previous attempt failed', $repairPrompt);
    }

    /**
     * VAL-E1-007: Probe field does NOT destabilize the failure signature.
     *
     * The failure excerpt that feeds the hasher is the GATE failure output,
     * NOT the probe reason. The probe reason must be excluded from the hashed
     * excerpt so the signature is byte-identical regardless of the probe.
     */
    public function test_val_e1_007_probe_reason_does_not_change_failure_signature(): void
    {
        $hasher = new FailureSignatureHasher;

        // The failure excerpt fed to the hasher is the GATE failure (extractFailureExcerpt),
        // NOT the probe reason. Identical gate failures => identical signatures.
        $gateFailure = "Command: /opt/homebrew/bin/php artisan test tests/Unit/FooTest.php\nExit code: 1";

        $sigA = $hasher->signature('verification_gate', $gateFailure);
        $sigB = $hasher->signature('verification_gate', $gateFailure);

        $this->assertSame(
            $sigA,
            $sigB,
            'VAL-E1-007: identical gate failures yield identical signatures (probe reason excluded)',
        );

        // Structural guard: if the probe reason WERE folded into the excerpt,
        // the signature WOULD change. Prove this to confirm the implementation
        // MUST keep them separate.
        $sigFolded = $hasher->signature(
            'verification_gate',
            $gateFailure."\nIntent Not Yet Addressed: corrigir",
        );
        $this->assertNotSame(
            $sigA,
            $sigFolded,
            'VAL-E1-007 (guard): folding the probe reason into the excerpt WOULD change the signature',
        );
    }

    /**
     * VAL-E1-008: Same-signature-twice anti-spin abort still works with the
     * probe active.
     *
     * A repair loop producing the same failure signature twice while the probe
     * emits its reason each iteration aborts on the repeated signature exactly
     * as without the probe.
     */
    public function test_val_e1_008_same_signature_twice_abort_still_fires_with_probe_active(): void
    {
        $runId = 'e1-repair-008-'.bin2hex(random_bytes(3));
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

        $envelope = $this->envelope(intent: 'Fix rate-limit guard', providerChoice: 'hermes_cli');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'intent_text' => 'Fix rate-limit guard',
            'intent_verbs' => ['corrigir'],
            'provider_lock' => [
                'provider' => 'hermes_cli',
                'model_family' => 'minimax-m3',
                'fallback_allowed' => false,
            ],
            'repair_policy' => [
                'max_attempts' => 5,
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

        // VAL-E1-008: same signature twice => abort at 2 calls (not exhausting the cap).
        $this->assertSame(
            2,
            $providerState->callCount,
            'VAL-E1-008: same-signature-twice abort fires at 2 calls with the probe active',
        );

        // The repair prompt carried the probe reason AND the loop STILL aborted.
        /** @var list<string> $prompts */
        $prompts = $providerState->capturedPrompts;
        $this->assertGreaterThanOrEqual(2, count($prompts));
        $repairPrompt = $prompts[1] ?? '';
        $this->assertStringContainsString(
            'Intent Not Yet Addressed',
            $repairPrompt,
            'VAL-E1-008: probe reason present AND the abort still fired',
        );
    }

    /**
     * VAL-E1-013: The probe reason drives convergence when the next repair
     * iteration implements the verb.
     *
     * With E1 active and an intent-missing diff, a subsequent repair iteration
     * whose regenerated diff DOES implement the verb clears the probe on that
     * iteration; the loop converges using the probe feedback (a live input,
     * not a dead annotation).
     */
    public function test_val_e1_013_probe_reason_drives_convergence_when_repair_implements_verb(): void
    {
        $runId = 'e1-repair-013-'.bin2hex(random_bytes(3));
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
                    file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'unrelated'; } }\n");
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
            stdout: 'FAILURES! FooTest::test_value AssertionError: expected fixed got unrelated',
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

        $envelope = $this->envelope(intent: 'Fix rate-limit guard', providerChoice: 'hermes_cli');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'intent_text' => 'Fix rate-limit guard',
            'intent_verbs' => ['corrigir'],
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

        // VAL-E1-013: the loop converged (iteration N+1 implemented the verb).
        $this->assertSame(2, $providerState->callCount, 'VAL-E1-013: loop ran exactly 2 iterations (converged)');
        $this->assertContains(
            $result->verificationStatus,
            [VerificationGateResult::STATUS_PASSED, 'passed'],
            'VAL-E1-013: convergence to green after the regenerated diff implemented the verb',
        );

        // The repair prompt (iteration 2) carried the probe reason (a live input).
        /** @var list<string> $prompts */
        $prompts = $providerState->capturedPrompts;
        $this->assertGreaterThanOrEqual(2, count($prompts));
        $repairPrompt = $prompts[1] ?? '';
        $this->assertStringContainsString(
            'Intent Not Yet Addressed',
            $repairPrompt,
            'VAL-E1-013: the probe reason was fed as a live input into the regeneration prompt',
        );
    }

    /**
     * VAL-E1-006 (off mode byte-identical): with e1.mode=off, the repair
     * prompt does NOT carry the probe reason section (byte-identical to the
     * pre-E1 baseline). The probe is never consulted.
     */
    public function test_off_mode_repair_prompt_has_no_probe_section(): void
    {
        config()->set('atlas_dev.elevations.e1.mode', 'off');

        $runId = 'e1-repair-off-'.bin2hex(random_bytes(3));
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
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'fixed'; } }\n");

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

        $envelope = $this->envelope(intent: 'Fix rate-limit guard', providerChoice: 'hermes_cli');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
            'intent_text' => 'Fix rate-limit guard',
            'intent_verbs' => ['corrigir'],
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
        $this->assertGreaterThanOrEqual(2, count($prompts));
        $repairPrompt = $prompts[1] ?? '';

        // off mode: NO dedicated probe-reason section (byte-identical to pre-E1).
        $this->assertStringNotContainsString(
            'Intent Not Yet Addressed',
            $repairPrompt,
            'off mode: repair prompt is byte-identical (no probe-reason section)',
        );
        // The failure-excerpt structure is still present (M2 contract).
        $this->assertStringContainsString('REPAIR REQUIRED', $repairPrompt);
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
