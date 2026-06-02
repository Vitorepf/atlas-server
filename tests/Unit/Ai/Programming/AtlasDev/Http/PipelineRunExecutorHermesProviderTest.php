<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Http;

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
 * Additive coverage for the `hermes_cli` provider branch added to
 * {@see PipelineRunExecutor::executeHermesProvider()}.
 *
 * The branch must:
 *   - resolve Hermes via app(AiProviderManager::class)->get('hermes_cli');
 *   - run Hermes inside the Dev workspace ($envelope->workspace) — so the
 *     job it builds carries the workspace keys workdirForJob() reads;
 *   - derive the post-run workspace diff and route it through scope +
 *     verification (Claude must NOT be invoked).
 *
 * A FAKE AiProvider is bound into the AiProviderManager so NO real Hermes
 * tokens are spent. The fake edits the allowed file to simulate Hermes
 * mutating the worktree, and records the AiJob it received so the test can
 * assert the cwd wiring.
 */
final class PipelineRunExecutorHermesProviderTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-hermes-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-hermes-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_hermes_provider_lock_routes_to_hermes_branch_and_uses_workspace_diff_without_claude(): void
    {
        // The deterministic fast path would short-circuit before any provider
        // is consulted; disable it so the hermes branch is actually exercised.
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        $runId = 'dev-hermes-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        // FAKE Hermes provider: simulates the executive runtime mutating the
        // Dev worktree directly, returns a successful AiProviderResult, and
        // captures the AiJob so we can prove the cwd wiring. Spends NO tokens.
        $capturedJobs = [];
        $fakeHermes = new class($target, $capturedJobs) implements AiProvider
        {
            /** @param array<int,AiJob> $capturedJobs */
            public function __construct(
                private readonly string $target,
                public array &$capturedJobs,
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
                $this->capturedJobs[] = $job;
                file_put_contents($this->target, "<?php\nfinal class Foo { public function value(): string { return 'after-hermes'; } }\n");

                return new AiProviderResult(
                    ok: true,
                    output: 'Hermes edited app/Foo.php as instructed.',
                    command: ['hermes', 'chat', '--quiet'],
                    exitCode: 0,
                    durationMs: 4242,
                    stdout: 'Hermes edited app/Foo.php as instructed.',
                    stderr: '',
                    errorCode: null,
                    errorMessage: null,
                    metadata: ['hermes_runtime' => ['role' => 'executive_runtime']],
                );
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(provider: 'hermes_cli', status: 'online', message: 'fake');
            }
        };

        // Override hermes_cli in the AiProviderManager and bind that exact
        // instance into the GLOBAL app container, because the executor resolves
        // the manager via app(AiProviderManager::class), not via its injected
        // (test) container.
        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'php -l app/Foo.php',
            exitCode: 0,
            stdout: 'No syntax errors detected',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->envelope(
            intent: 'Use Hermes to change app/Foo.php so value returns after-hermes.',
            providerChoice: 'hermes_cli',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['php -l app/Foo.php'],
            'provider_lock' => [
                'provider' => 'hermes_cli',
                'model_family' => 'minimax-m3',
                'fallback_allowed' => false,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // Routed to the hermes branch, not to Claude and not to the default
        // unsupported_provider_lock arm.
        $this->assertSame([], $gateway->requests, 'hermes_cli provider lock must NOT dispatch ClaudeCliGateway.');
        $this->assertSame('hermes_cli', $result->providerCallSummary['provider']);
        $this->assertSame('minimax-m3', $result->providerCallSummary['model_family']);
        $this->assertSame(1, $result->providerCallSummary['provider_calls'], 'fake Hermes provider must have been invoked exactly once');
        $this->assertNotContains(
            'unsupported_provider_lock:hermes_cli',
            $result->providerCallSummary['error_codes'],
            'hermes_cli must be a supported provider lock',
        );
        $this->assertContains($result->completionState, ['passed', 'completed'], json_encode([
            'provider' => $result->providerCallSummary,
            'diff' => $result->diffParseSummary,
            'scope_guard_status' => $result->scopeGuardStatus,
            'verification_status' => $result->verificationStatus,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Result mapping: duration came from the AiProviderResult.
        $this->assertSame(4242, $result->providerCallSummary['duration_ms']);

        // The fake (standing in for Hermes) mutated the workspace and Atlas
        // derived the diff from it.
        $this->assertStringContainsString("return 'after-hermes';", (string) file_get_contents($target));

        // cwd wiring: the job Hermes received pins the Dev workspace in BOTH
        // keys workdirForJob() reads, so Hermes would run IN the worktree.
        $this->assertCount(1, $capturedJobs);
        $job = $capturedJobs[0];
        $this->assertSame($this->tmpWorkspace, data_get($job->payload, 'workspace'));
        $this->assertSame($this->tmpWorkspace, data_get($job->payload, 'tool_permissions.workspace'));
        $this->assertSame('hermes_cli', $job->provider);
        $this->assertSame('minimax-m3', $job->model);

        // Hermes is treated as a workspace mutator — the diff is NOT re-applied.
        $apply = $storage->read($runId, ArtifactNames::PATCH_APPLY_RESULT);
        $this->assertIsArray($apply);
        $this->assertSame('skipped', $apply['status']);
        $this->assertSame('provider_mutated_workspace', $apply['reason']);
    }

    public function test_hermes_branch_blocks_when_provider_cannot_be_resolved(): void
    {
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        $runId = 'dev-hermes-unbound-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        // Bind a manager whose hermes_cli driver fails to resolve to an
        // AiProvider, so the guard path is exercised (no tokens, no crash).
        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', static fn () => throw new \RuntimeException('boom'));
        app()->instance(AiProviderManager::class, $manager);

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->envelope(providerChoice: 'hermes_cli');
        $taskContract = $this->taskContractFixture([
            'provider_lock' => [
                'provider' => 'hermes_cli',
                'model_family' => 'minimax-m3',
                'fallback_allowed' => false,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests);
        $this->assertSame('hermes_cli', $result->providerCallSummary['provider']);
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertContains('hermes_cli_provider_unavailable', $result->providerCallSummary['error_codes']);
        $this->assertNotContains('unsupported_provider_lock:hermes_cli', $result->providerCallSummary['error_codes']);
    }

    // ------------------------------------------------------------------
    // Helpers (mirrors PipelineRunExecutorTest)
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
