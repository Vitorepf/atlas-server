<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * F-03 regression: VerificationReceipt's task_kind / risk_level must come
 * from the persisted CompactSDD — never from surfaceContext.composer_task,
 * and never from a hardcoded fallback like the old `R2`.
 *
 * These tests drive a real {@see PipelineRunExecutor} with fake provider +
 * command runner, varying only the CompactSDD payload, and assert the
 * receipt mirrors what was planned.
 */
final class PipelineRunExecutorTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-exec-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-exec-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_compact_sdd_risk_r4_produces_receipt_risk_r4_not_a_hardcoded_default(): void
    {
        $runId = 'dev-r4-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $this->seedRun($storage, $runId, taskKind: 'risky', riskLevel: 'R4');

        $executor = $this->makeExecutor($storage, gatewayStdout: 'no_patch_needed: true');
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture();
        $projection = $this->buildSendableProjection(envelope: $envelope);

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $projection,
            runId: $runId,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertSame('R4', $receipt->riskLevel);
        $this->assertSame('risky', $receipt->taskKind);
    }

    public function test_surface_composer_task_does_not_override_compact_sdd_task_kind(): void
    {
        $runId = 'dev-cmp-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // CompactSDD says repair (canonical task_kind vocabulary). Surface
        // context says debug (router/composer vocabulary). Receipt MUST
        // mirror CompactSDD, not the surface label.
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $envelope = $this->envelope(composerTask: 'debug');
        $taskContract = $this->taskContractFixture();
        $projection = $this->buildSendableProjection(envelope: $envelope);

        $executor = $this->makeExecutor($storage, gatewayStdout: 'no_patch_needed: true');

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $projection,
            runId: $runId,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertSame('repair', $receipt->taskKind);
        $this->assertSame('R2', $receipt->riskLevel);
        $this->assertSame('debug', $envelope->surfaceContext->composerTask, 'surface field stays untouched');
    }

    public function test_provider_timeout_comes_from_atlas_dev_config(): void
    {
        config()->set('atlas_dev.provider.timeout_seconds', 7);

        $runId = 'dev-timeout-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: 'no_patch_needed: true'));

        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture();

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame(7, $gateway->requests[0]->timeoutSeconds);
    }

    public function test_aucri_runtime_enforcement_blocks_sensitive_prompt_before_provider_call(): void
    {
        $runId = 'dev-aucri-block-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: 'no_patch_needed: true'));
        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope(intent: 'corrija o teste usando api_key=sk-AUCRISEGREDO1234567890');

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $this->taskContractFixture(),
            promptProjection: $this->buildSendableProjection(envelope: $envelope),
            runId: $runId,
        );

        $this->assertSame('blocked', $result->completionState);
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertSame([], $gateway->requests, 'AUCRI must block before Claude CLI is invoked.');
        $this->assertContains('privacy:secret_context_requires_local_only', $result->providerCallSummary['error_codes']);

        $enforcement = $storage->read($runId, ArtifactNames::AUCRI_RUNTIME_ENFORCEMENT);
        $this->assertIsArray($enforcement);
        $this->assertSame('atlas.aucri.runtime_enforcement.v1', $enforcement['schema_version']);
        $this->assertSame('blocked', $enforcement['status']);
        $this->assertSame(true, data_get($enforcement, 'claims.enforced_before_provider_call'));
        $this->assertSame(true, data_get($enforcement, 'claims.all_18_aucri_blocks_executed'));
        $this->assertSame(18, data_get($enforcement, 'block_ref_summary.total'));
        $this->assertSame(18, data_get($enforcement, 'block_ref_summary.executed'));
        $this->assertSame([
            'ASEF', 'AHRI', 'AARF', 'ACRS', 'ACFQ', 'ARFL', 'AGRN', 'AURG', 'APDR',
            'AREBA', 'ARCLG', 'ACOP', 'ARPTL', 'AKIF', 'ACMF', 'ACCR', 'ATER', 'ACPFR',
        ], data_get($enforcement, 'block_ref_summary.acronyms'));
        $this->assertStringNotContainsString('sk-AUCRISEGREDO', json_encode($enforcement, JSON_THROW_ON_ERROR));
    }

    public function test_patch_diff_is_applied_to_workspace_before_verification(): void
    {
        $runId = 'dev-apply-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nassert(false);\n");

        $diff = <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);
DIFF;

        $executor = $this->makeExecutor($storage, gatewayStdout: $diff);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/Services/Foo/FooServiceTest.php'],
            'expected_max_files' => 2,
            'max_files_changed' => 2,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $apply = $storage->read($runId, ArtifactNames::PATCH_APPLY_RESULT);
        $this->assertIsArray($apply);
        $this->assertSame('applied', $apply['status'], json_encode($apply, JSON_PRETTY_PRINT));

        $this->assertSame("<?php\nassert(true);\n", file_get_contents($target));
        $this->assertSame('passed', $result->completionState);

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertSame(['tests/Unit/Services/Foo/FooServiceTest.php'], $receipt->changedFiles);
    }

    public function test_failed_verification_persists_test_log_artifact_for_repair_evidence(): void
    {
        $runId = 'dev-failed-log-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nassert(false);\n");

        $diff = <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);
DIFF;

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $diff));
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'php artisan test tests/Unit/Services/Foo/FooServiceTest.php',
            exitCode: 1,
            stdout: "FAIL FooServiceTest\nExpected true to be false.\n",
            stderr: '',
            durationMs: 12,
        ));
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);

        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/Services/Foo/FooServiceTest.php'],
            'validation_commands' => ['php artisan test tests/Unit/Services/Foo/FooServiceTest.php'],
            'max_files_changed' => 1,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('failed', $result->completionState);

        $receipt = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($receipt);
        $outputPath = (string) data_get($receipt, 'tests.0.output_path');
        $this->assertNotSame('', $outputPath);
        $this->assertFileExists($outputPath);

        $log = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.dev.verification_test_log.v1', $log['schema_version']);
        $this->assertSame($runId, $log['run_id']);
        $this->assertStringContainsString('FAIL FooServiceTest', $log['combined_output']);
        $this->assertSame(hash('sha256', $log['combined_output']), $log['output_hash']);
    }

    public function test_simple_allowed_file_patch_can_run_without_provider_call(): void
    {
        $runId = 'dev-deterministic-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/src/SmokeSubject.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class SmokeSubject { public function greeting(): string { return 'helo atlas'; } }\n");

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope(
            intent: 'Corrija o typo em src/SmokeSubject.php: retorna helo atlas mas o teste espera hello atlas.',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/SmokeSubject.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests, 'deterministic fast path must not call the provider');
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertSame('atlas_deterministic', $result->providerCallSummary['provider']);
        $this->assertSame('passed', $result->completionState);
        $this->assertStringContainsString("return 'hello atlas';", (string) file_get_contents($target));
    }

    public function test_deterministic_patch_allows_validation_context_files_without_provider_call(): void
    {
        $runId = 'dev-deterministic-context-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R1');

        $target = $this->tmpWorkspace.'/src/SmokeSubject.php';
        $test = $this->tmpWorkspace.'/tests/SmokeSubjectTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($test), 0o755, true);
        file_put_contents($target, "<?php\nfinal class SmokeSubject { public function greeting(): string { return 'helo atlas'; } }\n");
        file_put_contents($test, "<?php\n// validation-only context fixture\n");

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope(
            intent: 'Corrija o typo em src/SmokeSubject.php: o metodo greeting retorna helo atlas mas o teste tests/SmokeSubjectTest.php espera hello atlas.',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/SmokeSubject.php', 'tests/SmokeSubjectTest.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests, 'validation context files must not force a provider call');
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertSame('passed', $result->completionState);
        $this->assertStringContainsString("return 'hello atlas';", (string) file_get_contents($target));
    }

    public function test_scoped_frontend_class_change_can_run_without_provider_call(): void
    {
        $runId = 'dev-deterministic-ui-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'frontend', riskLevel: 'R3');

        $target = $this->tmpWorkspace.'/resources/views/status-card.blade.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<section class=\"status-card compact\">\n  <h2>Deploy status</h2>\n</section>\n");

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope(
            intent: 'Implement a scoped frontend UI change: add the elevated class to resources/views/status-card.blade.php while preserving compact.',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['resources/views/status-card.blade.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests, 'deterministic frontend fast path must not call the provider');
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertSame('atlas_deterministic', $result->providerCallSummary['provider']);
        $this->assertSame('passed', $result->completionState);
        $this->assertStringContainsString('status-card compact elevated', (string) file_get_contents($target));
    }

    public function test_cursor_provider_lock_dispatches_cursor_driver_and_uses_workspace_diff_without_claude(): void
    {
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);
        config()->set('atlas.ai.providers.cursor_cli.binary', $this->installFakeCursorAgent());
        config()->set('atlas.ai.providers.cursor_cli.binary_candidates', []);
        config()->set('atlas.ai.providers.cursor_cli.auth_mode', 'local_login');
        config()->set('atlas.ai.providers.cursor_cli.output_format', 'stream-json');
        config()->set('atlas.ai.providers.cursor_cli.force', false);

        $runId = 'dev-cursor-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $capturedArgv = [];
        $runner = new AtlasForgeProviderProcessRunner;
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout) use ($target, &$capturedArgv): Process {
            $capturedArgv = $argv;
            file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'after'; } }\n");

            return new Process([PHP_BINARY, '-r', 'echo "cursor ok";'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCursorCliInvocationDriver(
            app(AtlasForgeProviderCommandAllowlistService::class),
            $runner,
            app(AtlasForgeProviderInvocationFailureClassifier::class),
        );

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
        $container->instance(AtlasForgeCursorCliInvocationDriver::class, $driver);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->envelope(
            intent: 'Change app/Foo.php so value returns after.',
            providerChoice: 'cursor_cli',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['php -l app/Foo.php'],
            'provider_lock' => [
                'provider' => 'cursor_cli',
                'model_family' => 'composer-2.5-fast',
                'fallback_allowed' => false,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests, 'cursor_cli provider lock must not dispatch ClaudeCliGateway.');
        $this->assertSame('cursor_cli', $result->providerCallSummary['provider']);
        $this->assertSame('composer-2.5-fast', $result->providerCallSummary['model_family']);
        $this->assertSame(1, $result->providerCallSummary['provider_calls']);
        $this->assertSame('passed', $result->completionState);
        $this->assertStringContainsString("return 'after';", (string) file_get_contents($target));
        $promptArg = (string) end($capturedArgv);
        $this->assertStringContainsString('Open .atlas/provider-prompts/cursor-cli/', $promptArg);
        $this->assertStringContainsString('read prompt.rendered_prompt_text', $promptArg);
        $this->assertStringContainsString('Edit only allowed_files.', $promptArg);
        $this->assertMatchesRegularExpression('/\\.atlas\\/provider-prompts\\/cursor-cli\\/[^\\s]+\\.json/', $promptArg);

        $apply = $storage->read($runId, ArtifactNames::PATCH_APPLY_RESULT);
        $this->assertIsArray($apply);
        $this->assertSame('skipped', $apply['status']);
        $this->assertSame('provider_mutated_workspace', $apply['reason']);

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertSame(['app/Foo.php'], $receipt->changedFiles);
    }

    public function test_cursor_workspace_diff_includes_new_untracked_allowed_files(): void
    {
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);
        config()->set('atlas.ai.providers.cursor_cli.binary', $this->installFakeCursorAgent());
        config()->set('atlas.ai.providers.cursor_cli.binary_candidates', []);
        config()->set('atlas.ai.providers.cursor_cli.auth_mode', 'local_login');
        config()->set('atlas.ai.providers.cursor_cli.output_format', 'stream-json');
        config()->set('atlas.ai.providers.cursor_cli.force', false);

        $runId = 'dev-cursor-new-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $source = $this->tmpWorkspace.'/app/Foo.php';
        $test = $this->tmpWorkspace.'/tests/Unit/FooTest.php';
        mkdir(dirname($source), 0o755, true);
        file_put_contents($source, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $runner = new AtlasForgeProviderProcessRunner;
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout) use ($test): Process {
            mkdir(dirname($test), 0o755, true);
            file_put_contents($test, "<?php\nit('pins foo', function (): void { expect(true)->toBeTrue(); });\n");

            return new Process([PHP_BINARY, '-r', 'echo "cursor ok";'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCursorCliInvocationDriver(
            app(AtlasForgeProviderCommandAllowlistService::class),
            $runner,
            app(AtlasForgeProviderInvocationFailureClassifier::class),
        );

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'php -l tests/Unit/FooTest.php',
            exitCode: 0,
            stdout: 'No syntax errors detected',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(AtlasForgeCursorCliInvocationDriver::class, $driver);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->envelope(
            intent: 'Create the focused unit test tests/Unit/FooTest.php.',
            providerChoice: 'cursor_cli',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'max_files_changed' => 2,
            'validation_commands' => ['php -l tests/Unit/FooTest.php'],
            'provider_lock' => [
                'provider' => 'cursor_cli',
                'model_family' => 'composer-2.5-fast',
                'fallback_allowed' => false,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('passed', $result->completionState);

        $diff = $storage->read($runId, ArtifactNames::DIFF_PARSE_RESULT);
        $this->assertIsArray($diff);
        $this->assertSame('patch', $diff['mode']);
        $this->assertSame(['tests/Unit/FooTest.php'], $diff['changed_files']);
        $this->assertStringContainsString('new file mode', (string) $diff['diff']);
        $this->assertStringContainsString('+++ b/tests/Unit/FooTest.php', (string) $diff['diff']);
    }

    public function test_codex_provider_lock_dispatches_codex_driver_and_uses_workspace_diff_without_claude(): void
    {
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        config()->set('atlas.ai.providers.codex_cli.binary', $this->installFakeCodexCli());
        config()->set('atlas.ai.providers.codex_cli.args', ['exec', '--skip-git-repo-check']);
        putenv('CODEX_API_KEY=atlas-test-key');

        $runId = 'dev-codex-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/Foo.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'before'; } }\n");
        $this->git(['add', 'app/Foo.php']);
        $this->git(['commit', '-m', 'fixture']);

        $capturedArgv = [];
        $runner = new AtlasForgeProviderProcessRunner;
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout) use ($target, &$capturedArgv): Process {
            $capturedArgv = $argv;
            file_put_contents($target, "<?php\nfinal class Foo { public function value(): string { return 'after-codex'; } }\n");

            return new Process([PHP_BINARY, '-r', 'echo "codex ok";'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCodexCliInvocationDriver(
            app(AtlasForgeProviderCommandAllowlistService::class),
            $runner,
            app(AtlasForgeProviderInvocationFailureClassifier::class),
        );

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
        $container->instance(AtlasForgeCodexCliInvocationDriver::class, $driver);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->envelope(
            intent: 'Review and repair app/Foo.php before commit.',
            providerChoice: 'codex_cli',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Foo.php'],
            'max_files_changed' => 1,
            'validation_commands' => ['php -l app/Foo.php'],
            'provider_lock' => [
                'provider' => 'codex_cli',
                'model_family' => 'gpt-5.5',
                'fallback_allowed' => false,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame([], $gateway->requests, 'codex_cli provider lock must not dispatch ClaudeCliGateway.');
        $this->assertSame('codex_cli', $result->providerCallSummary['provider']);
        $this->assertSame('gpt-5.5', $result->providerCallSummary['model_family']);
        $this->assertSame(1, $result->providerCallSummary['provider_calls']);
        $this->assertSame('passed', $result->completionState, json_encode([
            'provider' => $result->providerCallSummary,
            'diff' => $result->diffParseSummary,
            'scope_guard_status' => $result->scopeGuardStatus,
            'verification_status' => $result->verificationStatus,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString("return 'after-codex';", (string) file_get_contents($target));
        $this->assertContains('exec', $capturedArgv);
        $this->assertContains('--skip-git-repo-check', $capturedArgv);
        $this->assertContains('--sandbox', $capturedArgv);
        $this->assertContains('workspace-write', $capturedArgv);
        $this->assertContains('-', $capturedArgv);

        $apply = $storage->read($runId, ArtifactNames::PATCH_APPLY_RESULT);
        $this->assertIsArray($apply);
        $this->assertSame('skipped', $apply['status']);
        $this->assertSame('provider_mutated_workspace', $apply['reason']);
    }

    public function test_invalid_provider_output_persists_provider_and_diff_parse_artifacts(): void
    {
        $runId = 'dev-invalid-output-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $rawOutput = "I looked at the code and it seems fine.\n";
        $executor = $this->makeExecutor($storage, gatewayStdout: $rawOutput);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture();

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('failed', $result->completionState);
        $this->assertSame('invalid', $result->diffParseSummary['mode'] ?? null);
        $this->assertSame(['no_unified_diff_detected', 'no_no_patch_needed_marker', 'no_blocked_marker'], $result->diffParseSummary['errors'] ?? null);
        $this->assertSame(hash('sha256', $rawOutput), $result->providerCallSummary['raw_response_hash'] ?? null);
        $this->assertSame(strlen($rawOutput), $result->providerCallSummary['stdout_bytes'] ?? null);

        $providerArtifact = $storage->read($runId, ArtifactNames::PROVIDER_CALL_RESULT);
        $this->assertIsArray($providerArtifact);
        $this->assertSame($rawOutput, $providerArtifact['stdout']);
        $this->assertSame(hash('sha256', $rawOutput), $providerArtifact['raw_response_hash']);

        $diffArtifact = $storage->read($runId, ArtifactNames::DIFF_PARSE_RESULT);
        $this->assertIsArray($diffArtifact);
        $this->assertSame('invalid', $diffArtifact['mode']);
        $this->assertSame(['no_unified_diff_detected', 'no_no_patch_needed_marker', 'no_blocked_marker'], $diffArtifact['errors']);
        $this->assertNull($diffArtifact['diff']);
        $this->assertNull($diffArtifact['diff_hash']);
    }

    public function test_missing_compact_sdd_fails_closed_with_typed_exception(): void
    {
        $runId = 'dev-missing-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture();
        $projection = $this->buildSendableProjection(envelope: $envelope);

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);

        try {
            $executor->execute(
                envelope: $envelope,
                taskContract: $taskContract,
                promptProjection: $projection,
                runId: $runId,
            );
            $this->fail('Expected CompactSddUnavailableException to be thrown.');
        } catch (CompactSddUnavailableException $e) {
            $this->assertSame('COMPACT_SDD_MISSING', $e->errorCode());
            $this->assertSame($runId, $e->runId);
            $this->assertSame(CompactSddUnavailableException::REASON_MISSING, $e->reasonCode);
        }

        $this->assertSame(
            [],
            $gateway->requests,
            'provider must NOT be called when CompactSDD is missing — receipt would be unattestable',
        );
        $this->assertSame([], $commandRunner->calls, 'verification commands must not run either');
    }

    public function test_compact_sdd_with_unknown_task_kind_fails_closed_invalid(): void
    {
        $runId = 'dev-invalid-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Persist a compact_sdd.json with a task_kind that VerificationReceipt
        // would reject. Executor must catch this before invoking the provider.
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, [
            'task_kind' => 'not_a_real_kind',
            'risk_level' => 'R2',
        ]);

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);

        $envelope = $this->envelope();
        try {
            $executor->execute(
                envelope: $envelope,
                taskContract: $this->taskContractFixture(),
                promptProjection: $this->buildSendableProjection(envelope: $envelope),
                runId: $runId,
            );
            $this->fail('Expected CompactSddUnavailableException for invalid task_kind.');
        } catch (CompactSddUnavailableException $e) {
            $this->assertSame('COMPACT_SDD_INVALID', $e->errorCode());
            $this->assertSame(CompactSddUnavailableException::REASON_INVALID, $e->reasonCode);
            $this->assertStringContainsString('task_kind', $e->detail);
        }

        $this->assertSame([], $gateway->requests);
    }

    public function test_compact_sdd_with_unknown_risk_level_fails_closed_invalid(): void
    {
        $runId = 'dev-bad-risk-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, [
            'task_kind' => 'repair',
            'risk_level' => 'R99',
        ]);

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);

        $envelope = $this->envelope();
        try {
            $executor->execute(
                envelope: $envelope,
                taskContract: $this->taskContractFixture(),
                promptProjection: $this->buildSendableProjection(envelope: $envelope),
                runId: $runId,
            );
            $this->fail('Expected CompactSddUnavailableException for invalid risk_level.');
        } catch (CompactSddUnavailableException $e) {
            $this->assertSame('COMPACT_SDD_INVALID', $e->errorCode());
            $this->assertStringContainsString('risk_level', $e->detail);
        }

        $this->assertSame([], $gateway->requests);
    }

    /**
     * F-03 server-side pin: when the caller (RunController, sourced from
     * the HMAC-keyed confirmation_token row) supplies an expected
     * compact_sdd_hash that does NOT match the on-disk value, the executor
     * fails closed before invoking the provider.
     */
    public function test_server_side_hash_pin_mismatch_fails_closed_with_tampered(): void
    {
        $runId = 'dev-pin-mismatch-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $gateway = new FakeClaudeCliGateway;
        $commandRunner = new FakeCommandRunner;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);

        $envelope = $this->envelope();
        try {
            $executor->execute(
                envelope: $envelope,
                taskContract: $this->taskContractFixture(),
                promptProjection: $this->buildSendableProjection(envelope: $envelope),
                runId: $runId,
                // Forge a different expected hash to simulate the disk being
                // mutated AFTER the plan-time pin was captured.
                expectedCompactSddHash: str_repeat('f', 64),
            );
            $this->fail('Expected CompactSddUnavailableException when server-side pin disagrees.');
        } catch (CompactSddUnavailableException $e) {
            $this->assertSame('COMPACT_SDD_TAMPERED', $e->errorCode());
            $this->assertSame(CompactSddUnavailableException::REASON_TAMPERED, $e->reasonCode);
            $this->assertStringContainsString('server-side pin', $e->detail);
        }

        $this->assertSame(
            [],
            $gateway->requests,
            'Provider MUST NOT be called when the server-side compact_sdd pin disagrees.',
        );
        $this->assertSame([], $commandRunner->calls);
        $this->assertNull(
            $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT),
            'No verification_receipt should be persisted for a tampered run.',
        );
    }

    public function test_server_side_hash_pin_match_allows_run_to_complete(): void
    {
        $runId = 'dev-pin-match-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        // The hash currently on disk (re-read so we compare exactly what
        // resolveTaskKindAndRiskLevel will see).
        $compact = $storage->read($runId, ArtifactNames::COMPACT_SDD);
        $this->assertIsArray($compact);
        $expected = (string) $compact['compact_sdd_hash'];

        $executor = $this->makeExecutor($storage, gatewayStdout: 'no_patch_needed: true');
        $envelope = $this->envelope();

        $executor->execute(
            envelope: $envelope,
            taskContract: $this->taskContractFixture(),
            promptProjection: $this->buildSendableProjection(envelope: $envelope),
            runId: $runId,
            expectedCompactSddHash: $expected,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertSame('R2', $receipt->riskLevel);
        $this->assertSame('repair', $receipt->taskKind);
    }

    public function test_null_server_side_pin_falls_back_to_disk_only_chain(): void
    {
        // Legacy / CLI paths that pre-date the token row column may pass
        // null. The executor must still defend via the on-disk pin chain
        // (self-hash + mini_programming_spec pin).
        $runId = 'dev-null-pin-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $executor = $this->makeExecutor($storage, gatewayStdout: 'no_patch_needed: true');
        $envelope = $this->envelope();

        $executor->execute(
            envelope: $envelope,
            taskContract: $this->taskContractFixture(),
            promptProjection: $this->buildSendableProjection(envelope: $envelope),
            runId: $runId,
            expectedCompactSddHash: null,
        );

        $this->assertNotNull($storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(?string $composerTask = 'dev', ?string $intent = null, ?string $providerChoice = null): OperationEnvelope
    {
        $intent ??= 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: $composerTask,
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

    /**
     * Persist the artifacts the executor reads on entry. Provider call /
     * gates are exercised against the fake gateway + command runner, so the
     * fixture only needs to satisfy the executor's I/O contract.
     */
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

    private function makeExecutor(ReceiptStorage $storage, string $gatewayStdout): PipelineRunExecutor
    {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $gatewayStdout));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'echo verified',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));

        return $this->wireExecutor($storage, $gateway, $commandRunner);
    }

    private function wireExecutor(
        ReceiptStorage $storage,
        FakeClaudeCliGateway $gateway,
        FakeCommandRunner $commandRunner,
    ): PipelineRunExecutor {
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);

        return new PipelineRunExecutor($container, $storage);
    }

    private function loadReceipt(ReceiptStorage $storage, string $runId): VerificationReceipt
    {
        $payload = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($payload, 'verification_receipt.json must be persisted');

        return VerificationReceipt::fromArray($payload);
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

    private function installFakeCursorAgent(): string
    {
        $binary = $this->tmpStorage.'/cursor-agent';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0o755);

        return $binary;
    }

    private function installFakeCodexCli(): string
    {
        $binary = $this->tmpStorage.'/codex';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0o755);

        return $binary;
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
