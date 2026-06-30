<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * VAL-M1-010/011/012/013/016/018: the senior critic is provider-agnostic.
 *
 * Pre-M1 the senior critic (ReviewIntelligenceService) was gated on
 * `isHermesCli` at PipelineRunExecutor.php ~:764, so a non-hermes run whose
 * gate passed was NEVER semantically reviewed before `passed`. These tests
 * drive a real {@see PipelineRunExecutor} with a `claude_cli` provider lock +
 * fakes and assert the critic now runs for ANY locked provider after a passed
 * gate (including after a repair-to-green), a blocker forces non-passed, a
 * critic exception degrades to non-passed (never swallowed to green), and a
 * clean diff is not falsely blocked.
 *
 * The workspace uses a NON-PHP allowed file (`src/AtlasDevCriticProbe.txt`)
 * so the verification gate's M1 floor adds no extra commands (no php -l, no
 * impacted tests, no pint) — the gate runs ONLY the validation command. This
 * keeps the gate aggregate deterministic and isolates the critic behavior
 * (the critic still receives changed_files=[src/AtlasDevCriticProbe.txt]; a
 * non-code file yields no test_gap finding so a clean diff is no_concerns).
 */
final class CriticProviderAgnosticTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-critic-agnostic-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-critic-agnostic-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);

        // Isolate the critic from the elevation probes (E1/E2/E4/E5/E6) and
        // the deterministic fast path so the assertions observe critic
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
     * VAL-M1-010: a non-hermes (claude_cli) run whose gate passes invokes the
     * senior critic exactly once, and its review receipt feeds the completion
     * decision (critic_status non-null, critic_findings_count present).
     */
    public function test_critic_runs_for_claude_cli_after_passed_gate(): void
    {
        $runId = 'dev-critic-run-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationPassed());

        $capturingCritic = new CapturingCriticService;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $capturingCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        $this->assertSame('claude_cli', $result->providerCallSummary['provider']);
        // The critic was invoked exactly once for a non-hermes passed run.
        $this->assertCount(1, $capturingCritic->capturedInputs,
            'Critic must be invoked exactly once for a non-hermes passed run');
        $this->assertNotEmpty($capturingCritic->capturedInputs[0]['changed_files'],
            'Critic must receive non-empty changed_files');
        // The receipt feeds the completion decision.
        $this->assertNotNull($result->providerCallSummary['critic_status'],
            'critic_status must be recorded (proves the critic ran before completion)');
        $this->assertNotNull($result->providerCallSummary['critic_findings_count'],
            'critic_findings_count must be present in providerCallSummary');
    }

    /**
     * VAL-M1-011: a critic blocker (STATUS_ESCALATE) finding on a non-hermes
     * green run forces the completion to a non-passed state — never a silent
     * green over a critic blocker, regardless of provider.
     */
    public function test_critic_blocker_forces_non_passed_for_non_hermes_green_run(): void
    {
        $runId = 'dev-critic-blocker-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationPassed());

        $stubCritic = ControllableCriticService::escalate($runId);
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $stubCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        $this->assertNotSame('passed', $result->completionState,
            'A critic blocker must force non-passed for a non-hermes green run');
        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $result->providerCallSummary['critic_status']);
    }

    /**
     * VAL-M1-012: a critic that throws on a non-hermes passed run is captured
     * and the completion degrades to a non-passed state — the exception is
     * never silently swallowed to `passed`, and `critic_exception` is recorded.
     */
    public function test_critic_exception_degrades_to_non_passed_for_non_hermes_run(): void
    {
        $runId = 'dev-critic-throws-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationPassed());

        $throwingCritic = ControllableCriticService::throwing();
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $throwingCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        $this->assertNotSame('passed', $result->completionState,
            'A critic exception must degrade to non-passed (never swallowed to green)');
        $this->assertNotNull($result->providerCallSummary['critic_exception'],
            'critic_exception must be recorded (never silently dropped)');
    }

    /**
     * VAL-M1-013: a clean critic review (STATUS_NO_CONCERNS) on a non-hermes
     * passed run does NOT falsely block — the completion is `passed`. The
     * critic becoming provider-agnostic must not introduce false negatives
     * that block legitimate green runs. Uses the REAL ReviewIntelligenceService
     * on a clean non-code diff so the no-false-block is proven end-to-end.
     */
    public function test_clean_critic_does_not_falsely_block_non_hermes_green_run(): void
    {
        $runId = 'dev-critic-clean-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff()));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationPassed());

        // Real delegating critic so the no-false-block is proven against the
        // actual heuristics (a non-code .txt diff => no test_gap => no_concerns).
        $capturingCritic = new CapturingCriticService;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $capturingCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        $this->assertSame('passed', $result->completionState,
            'A clean critic on a non-hermes green run must yield passed (no false block)');
        $this->assertSame(ReviewReceipt::STATUS_NO_CONCERNS, $result->providerCallSummary['critic_status']);
    }

    /**
     * VAL-M1-018: a non-hermes run that fails first verification, repairs,
     * and the repaired diff passes the gate STILL passes through the senior
     * critic before `passed`. Guards against an implementation that only
     * invokes the critic on the no-repair fast path. The critic receipt must
     * feed the passed decision (repair_attempts >= 1 AND critic_status
     * non-null), and the run is `passed` only when the critic is clean.
     */
    public function test_non_hermes_repaired_to_green_still_passes_through_critic(): void
    {
        $runId = 'dev-critic-repair-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // original (fails gate)
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // repair (clears gate)

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationFailed());  // first verification fails
        $commandRunner->queue($this->validationPassed());  // repair clears the gate

        $capturingCritic = new CapturingCriticService;
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $capturingCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        // Repair fired for the non-hermes provider.
        $this->assertGreaterThanOrEqual(1, $result->providerCallSummary['repair_attempts'],
            'A non-hermes repaired-to-green run must have repair_attempts >= 1');
        // The critic still ran on the repaired-to-green diff before passed.
        $this->assertNotNull($result->providerCallSummary['critic_status'],
            'The senior critic must run on a repaired-to-green non-hermes diff before passed');
        $this->assertCount(1, $capturingCritic->capturedInputs,
            'Critic must be invoked exactly once on the repaired-to-green run (not skipped on the repair path)');
        // Clean critic => passed (proves the critic ran AND did not false-block).
        $this->assertSame('passed', $result->completionState,
            'A repaired-to-green non-hermes run with a clean critic must be passed');
    }

    /**
     * VAL-M1-018 (blocker-on-repaired variant): a non-hermes run repaired to
     * green whose critic returns a blocker must NOT be `passed` — the critic
     * verdict dominates even on the repair path.
     */
    public function test_non_hermes_repaired_to_green_with_critic_blocker_is_not_passed(): void
    {
        $runId = 'dev-critic-repair-blocker-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->seedProbeFile();

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // original (fails gate)
        $gateway->queue($this->gatewayResponse(stdout: $this->probeDiff())); // repair (clears gate)

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue($this->validationFailed());
        $commandRunner->queue($this->validationPassed());

        $stubCritic = ControllableCriticService::escalate($runId);
        $executor = $this->wireExecutor($storage, $gateway, $commandRunner, $stubCritic);
        $envelope = $this->envelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['src/AtlasDevCriticProbe.txt'],
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

        $this->assertGreaterThanOrEqual(1, $result->providerCallSummary['repair_attempts']);
        $this->assertNotNull($result->providerCallSummary['critic_status'],
            'Critic must run on the repaired-to-green non-hermes diff');
        $this->assertNotSame('passed', $result->completionState,
            'A critic blocker on a repaired-to-green non-hermes run must force non-passed');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(?string $intent = null): OperationEnvelope
    {
        $intent ??= 'Change src/AtlasDevCriticProbe.txt so the validation test passes.';

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
        object $criticService,
    ): PipelineRunExecutor {
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $criticService);

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

        $target = $this->tmpWorkspace.'/src/AtlasDevCriticProbe.txt';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "v1\n");
        $this->git(['add', 'src/AtlasDevCriticProbe.txt']);
        $this->git(['commit', '-m', 'fixture']);
    }

    private function probeDiff(): string
    {
        return <<<'DIFF'
--- a/src/AtlasDevCriticProbe.txt
+++ b/src/AtlasDevCriticProbe.txt
@@ -1 +1 @@
-v1
+v2
DIFF;
    }

    private function validationCommand(): string
    {
        return 'php artisan test tests/Unit/AtlasDevCriticProbeTest.php';
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
            stdout: 'FAIL AtlasDevCriticProbeTest',
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

/**
 * Capturing wrapper around the real {@see ReviewIntelligenceService} that
 * records every input it receives. ReviewIntelligenceService is final, so we
 * use composition (the executor resolves the critic from the container and
 * calls analyse() on whatever object is bound).
 */
class CapturingCriticService
{
    /** @var list<array<string,mixed>> */
    public array $capturedInputs = [];

    private ReviewIntelligenceService $inner;

    public function __construct()
    {
        $this->inner = new ReviewIntelligenceService;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $options
     */
    public function analyse(array $input, array $options = []): ReviewReceipt
    {
        $this->capturedInputs[] = $input;

        return $this->inner->analyse($input, $options);
    }
}

/**
 * Controllable critic stub for tests that need a fixed verdict or a throw.
 * Produces valid {@see ReviewReceipt} instances (no_concerns / escalate) so
 * the CompletionStateGate threads the verdict correctly.
 */
class ControllableCriticService
{
    /** @var list<array<string,mixed>> */
    public array $capturedInputs = [];

    private ?ReviewReceipt $fixedReceipt;

    private bool $throw;

    private function __construct(?ReviewReceipt $fixedReceipt, bool $throw)
    {
        $this->fixedReceipt = $fixedReceipt;
        $this->throw = $throw;
    }

    public static function noConcerns(string $runId): self
    {
        return new self(
            ReviewReceipt::issue(
                receiptId: 'rev-stub-no-concerns',
                runId: $runId,
                status: ReviewReceipt::STATUS_NO_CONCERNS,
                reviewedFiles: ['src/AtlasDevCriticProbe.txt'],
                findings: [],
                missingTestsCount: 0,
                confidence: 0.9,
                evidenceRefs: [],
                blockerReasons: [],
                createdAt: now()->toIso8601String(),
            ),
            false,
        );
    }

    public static function escalate(string $runId): self
    {
        $blocker = new ReviewFinding(
            findingId: 'stub_blocker_1',
            title: 'Stub blocker finding for non-hermes green run',
            severity: ReviewFinding::SEVERITY_BLOCKER,
            riskType: ReviewFinding::RISK_SECRET_LEAK,
            description: 'A stub blocker-severity finding forces non-passed regardless of provider.',
            remediation: 'Resolve the blocker before the run can be promoted to passed.',
            confidence: 0.95,
            file: 'src/AtlasDevCriticProbe.txt',
            evidenceRefKinds: ['diff'],
        );

        return new self(
            ReviewReceipt::issue(
                receiptId: 'rev-stub-escalate',
                runId: $runId,
                status: ReviewReceipt::STATUS_ESCALATE,
                reviewedFiles: ['src/AtlasDevCriticProbe.txt'],
                findings: [$blocker],
                missingTestsCount: 0,
                confidence: 0.9,
                evidenceRefs: [],
                blockerReasons: [],
                createdAt: now()->toIso8601String(),
            ),
            false,
        );
    }

    public static function throwing(): self
    {
        return new self(null, true);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $options
     */
    public function analyse(array $input, array $options = []): ReviewReceipt
    {
        $this->capturedInputs[] = $input;
        if ($this->throw) {
            throw new \RuntimeException('critic service unavailable for non-hermes run');
        }

        // @phpstan-ignore-next-line — fixedReceipt is always set when not throwing.
        return $this->fixedReceipt;
    }
}
