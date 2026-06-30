<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

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
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * VAL-M1-006/007/008/009/017: the repair loop is provider-agnostic.
 *
 * Pre-M1 the repair cap was hardcoded to 0 for any non-hermes provider
 * (`repairCap = isHermesCli ? min(3,...) : 0`), so claude/codex/cursor runs
 * that failed verification never entered the repair loop. These tests drive a
 * real {@see PipelineRunExecutor} with a `claude_cli` provider lock + fakes
 * and assert the repair loop now fires, honors the repair-policy cap + anti-
 * spin guards, composes a valid repair prompt without hermes transport, and
 * degrades honestly when a repair re-invocation itself errors.
 *
 * The workspace uses a NON-PHP allowed file (`src/AtlasDevRepairProbe.txt`) so
 * the verification gate's M1 floor adds no extra commands (no php -l, no
 * impacted tests, no pint) — the gate runs ONLY the validation command. This
 * keeps the failure signature deterministic across iterations (command +
 * exit_code of the single validation command), which is what the anti-spin
 * same-signature-twice guard compares.
 */
final class RepairProviderAgnosticTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-repair-agnostic-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-repair-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);

        // Isolate the repair loop from the elevation probes (E1/E2/E4/E5/E6)
        // and the deterministic fast path, so the assertions observe repair
        // behavior only. E3 is already off by default.
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6'] as $elevation) {
            config()->set("atlas_dev.elevations.{$elevation}.mode", 'off');
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M1-006: a non-hermes (claude_cli) run whose first verification fails
     * enters the repair loop — provider_calls >= 2 (original + repair) and
     * repair_attempts >= 1. The repair re-invokes the SAME claude_cli gateway.
     */
    public function test_repair_loop_fires_for_claude_cli_on_failed_verification(): void
    {
        $runId = 'dev-repair-fire-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // original
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // repair

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed());  // first verification fails
        $commandRunner->queue($this->validationPassed());  // repair clears the gate

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 1),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('claude_cli', $result->providerCallSummary['provider']);
        $this->assertGreaterThanOrEqual(2, $result->providerCallSummary['provider_calls']);
        $this->assertGreaterThanOrEqual(1, $result->providerCallSummary['repair_attempts']);
        $this->assertCount(2, $gateway->requests, 'original call + one repair call to the claude gateway');
        $this->assertSame('claude_cli', $gateway->requests[1]->provider, 'repair re-invokes the same non-hermes provider');
        // Repair cleared the gate -> an honest non-failed terminal state.
        // (passed today; once the senior critic is decoupled from hermes it may
        // surface needs_review instead — both are honest, neither is a crash.)
        $this->assertContains($result->completionState, ['passed', 'needs_review'], json_encode([
            'provider' => $result->providerCallSummary,
            'verification_status' => $result->verificationStatus,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * VAL-M1-007: the repair cap for a non-hermes provider derives from the
     * repair policy, not a hardcoded 0. With max_attempts >= 1 the loop
     * iterates (repair fires); with max_attempts = 0 it does not.
     */
    public function test_repair_cap_for_non_hermes_allows_repairs_when_policy_permits(): void
    {
        $runId = 'dev-repair-cap-2-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed());
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 2),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // cap = min(3, 2) = 2 -> the loop iterates (pre-M1 cap was 0 -> no repair).
        $this->assertGreaterThanOrEqual(1, $result->providerCallSummary['repair_attempts']);
        $this->assertGreaterThanOrEqual(2, $result->providerCallSummary['provider_calls']);
        $this->assertSame('failed', $result->completionState);
    }

    /**
     * VAL-M1-007 (complement): with max_attempts = 0 the cap is 0, so a non-
     * hermes run does NOT repair (repair_attempts = 0, single provider call).
     */
    public function test_repair_cap_for_non_hermes_is_zero_when_policy_forbids(): void
    {
        $runId = 'dev-repair-cap-0-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 0),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame(0, $result->providerCallSummary['repair_attempts']);
        $this->assertSame(1, $result->providerCallSummary['provider_calls']);
        $this->assertSame('failed', $result->completionState);
    }

    /**
     * VAL-M1-008: the same-signature-twice anti-spin guard fires for a non-
     * hermes provider. When a claude repair produces the identical failure
     * signature twice in a row, the loop aborts with
     * `repair_loop_terminated:same_signature_twice` and an honest `failed`.
     */
    public function test_same_signature_twice_aborts_repair_for_non_hermes(): void
    {
        $runId = 'dev-repair-sig-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed()); // identical signature both iterations
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 2),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('failed', $result->completionState);
        $this->assertSame('same_signature_twice', $result->providerCallSummary['repair_abort_reason']);
        $this->assertNotEmpty($result->providerCallSummary['repair_attempts']);
    }

    /**
     * VAL-M1-008: the cap-exhaustion anti-spin guard fires for a non-hermes
     * provider. When a claude repair produces a DIFFERENT failure signature
     * each iteration (so same-signature-twice does not catch it), the loop
     * exhausts the cap and aborts with
     * `repair_loop_terminated:validation_failed_after_max_repairs`.
     */
    public function test_cap_exhaustion_aborts_repair_for_non_hermes(): void
    {
        $runId = 'dev-repair-cap-exh-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        // Different exit codes => different failure signatures, so the
        // same-signature-twice guard does NOT fire and the cap is the bound.
        $commandRunner->queue($this->validationFailed(exitCode: 1));
        $commandRunner->queue($this->validationFailed(exitCode: 2));

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 1),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame('failed', $result->completionState);
        $this->assertSame(
            'validation_failed_after_max_repairs',
            $result->providerCallSummary['repair_abort_reason']
        );
    }

    /**
     * VAL-M1-009: the repair prompt composed for a non-hermes repair attempt
     * carries the failure context (Repair Capsule, primary error, stop
     * conditions, REPAIR REQUIRED marker) and does NOT assume hermes transport
     * — the re-invocation uses the claude_cli runtime driver.
     */
    public function test_repair_prompt_composer_builds_without_hermes_transport(): void
    {
        $runId = 'dev-repair-prompt-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed());
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 1),
        ]);

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // The second gateway dispatch is the repair re-invocation.
        $this->assertCount(2, $gateway->requests, 'repair re-invocation must hit the provider gateway');
        $repairRequest = $gateway->requests[1];
        $this->assertSame('claude_cli', $repairRequest->provider, 'repair routes to the non-hermes gateway');
        $repairPrompt = $repairRequest->promptProjection->renderedPromptText;

        $this->assertStringContainsString('REPAIR REQUIRED', $repairPrompt);
        $this->assertStringContainsString('# Repair Capsule', $repairPrompt);
        $this->assertStringContainsString('# Primary Error (normalized)', $repairPrompt);
        $this->assertStringContainsString('# Stop Conditions', $repairPrompt);
        $this->assertStringContainsString('stop_if_max_repair_attempts_reached', $repairPrompt);
        $this->assertStringContainsString('failure_signature', $repairPrompt);
        // The capsule records the actual non-hermes provider lock, not hermes.
        $this->assertStringContainsString('claude_cli', $repairPrompt);
        $this->assertStringNotContainsString('hermes_cli', $repairPrompt);
    }

    /**
     * VAL-M1-017: a repair re-invocation that returns non-ok (provider exit
     * non-zero / empty stdout) degrades honestly to `failed`, bounded by the
     * cap, never a false `passed`. The provider error is surfaced in reasons.
     */
    public function test_erroring_repair_reinvocation_degrades_to_failed_within_cap(): void
    {
        $runId = 'dev-repair-err-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // original: valid diff, fails gate
        // Repair re-invocation itself errors: non-zero exit, empty stdout.
        $gateway->queue($this->gatewayResponse(stdout: '', exitCode: 1));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        // Both iterations: the single validation command fails identically
        // (same signature) so the loop aborts via same-signature-twice at the
        // cap, keeping repair_attempts <= cap.
        $commandRunner->queue($this->validationFailed());
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 2),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertContains($result->completionState, ['failed', 'blocked'], json_encode([
            'provider' => $result->providerCallSummary,
            'verification_status' => $result->verificationStatus,
        ], JSON_PRETTY_PRINT));
        $this->assertNotSame('passed', $result->completionState);
        // Bounded by the cap: repair_attempts never exceeds min(3, maxAttempts)=2.
        $this->assertLessThanOrEqual(2, $result->providerCallSummary['repair_attempts']);
        // The provider error is surfaced honestly.
        $this->assertContains('provider_exit_1', $result->providerCallSummary['error_codes']);
    }

    /**
     * VAL-M1-017 (driver-throws variant): a repair re-invocation whose driver
     * THROWS is caught and degrades honestly to `failed`, bounded by the cap,
     * never a crash and never a false `passed`.
     */
    public function test_throwing_repair_reinvocation_degrades_to_failed_within_cap(): void
    {
        $runId = 'dev-repair-throw-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // original
        // Repair re-invocation: the gateway itself throws (simulating an
        // unbound/crashing driver on the repair iteration).
        $gateway->queue(new \RuntimeException('claude gateway crashed on repair re-invocation'));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->defaultSuccess = false;
        $commandRunner->queue($this->validationFailed());
        $commandRunner->queue($this->validationFailed());

        $executor = $this->wireExecutor($storage, $gateway, $commandRunner);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevRepairProbe.txt'],
            'validation_commands' => [$this->validationCommand()],
            'max_files_changed' => 1,
            'repair_policy' => $this->repairPolicy(maxAttempts: 2),
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertContains($result->completionState, ['failed', 'blocked'], json_encode([
            'provider' => $result->providerCallSummary,
            'verification_status' => $result->verificationStatus,
        ], JSON_PRETTY_PRINT));
        $this->assertNotSame('passed', $result->completionState);
        $this->assertLessThanOrEqual(2, $result->providerCallSummary['repair_attempts']);
        $this->assertContains('provider_invocation_threw', $result->providerCallSummary['error_codes']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(?string $intent = null): OperationEnvelope
    {
        $intent ??= 'Change src/AtlasDevRepairProbe.txt so the validation test passes.';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: 'claude_cli',
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

    private function seedRun(ReceiptStorage $storage, string $runId): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => 'repair', 'risk_level' => 'R2']);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpec = $this->miniSpecFixture(['compact_sdd_hash' => $compactPayload['compact_sdd_hash']]);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
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

    /**
     * Create + commit the non-PHP probe file in a git workspace so the repair
     * loop's `revertWorkspaceChanges` (git checkout) restores HEAD between
     * iterations.
     */
    private function seedProbeFile(): void
    {
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'atlas-test@example.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);

        $target = $this->tmpWorkspace.'/src/AtlasDevRepairProbe.txt';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "v1\n");
        $this->git(['add', 'src/AtlasDevRepairProbe.txt']);
        $this->git(['commit', '-m', 'fixture']);
    }

    private function probeDiff(): string
    {
        return <<<'DIFF'
--- a/src/AtlasDevRepairProbe.txt
+++ b/src/AtlasDevRepairProbe.txt
@@ -1 +1 @@
-v1
+v2
DIFF;
    }

    private function validationCommand(): string
    {
        return 'php artisan test tests/Unit/AtlasDevRepairProbeTest.php';
    }

    private function validationPassed(): VerificationCommandResult
    {
        return new VerificationCommandResult(
            command: $this->validationCommand(),
            exitCode: 0,
            stdout: 'OK (1 test)',
            stderr: '',
            durationMs: 8,
        );
    }

    private function validationFailed(int $exitCode = 1): VerificationCommandResult
    {
        return new VerificationCommandResult(
            command: $this->validationCommand(),
            exitCode: $exitCode,
            stdout: 'FAIL AtlasDevRepairProbeTest',
            stderr: '',
            durationMs: 9,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function repairPolicy(int $maxAttempts): array
    {
        return [
            'max_attempts' => $maxAttempts,
            'abort_on_same_signature_twice' => true,
            'requires_failed_gate_output' => true,
            'same_provider' => true,
        ];
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
