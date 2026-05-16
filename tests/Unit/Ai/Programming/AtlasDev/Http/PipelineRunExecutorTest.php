<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Container\Container;
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(?string $composerTask = 'dev'): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: $composerTask,
            ),
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            normalizedIntent: 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
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
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactSdd->toCanonicalArray());

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
}
