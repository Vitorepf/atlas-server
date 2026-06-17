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
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * Capturing wrapper for ReviewIntelligenceService.
 *
 * Since ReviewIntelligenceService is final, we use composition instead of
 * inheritance. This wrapper delegates to the real service while recording
 * every input it receives. Also supports an optional operation log so tests
 * can verify the order of operations (provider calls vs critic invocation).
 */
class CapturingCriticService
{
    /** @var list<array<string,mixed>> */
    public array $capturedInputs = [];

    /** @var list<string> */
    public array $operationLog = [];

    private ReviewIntelligenceService $inner;

    public function __construct()
    {
        $this->inner = new ReviewIntelligenceService;
    }

    public function analyse(array $input): ReviewReceipt
    {
        $this->capturedInputs[] = $input;
        $this->operationLog[] = 'critic_invoked';

        return $this->inner->analyse($input);
    }
}

/**
 * Tests for M3 Senior Critic on the default hermes path.
 *
 * VAL-M3-001 through VAL-M3-009 and VAL-CROSS-002 assertions.
 *
 * These tests exercise the senior critic by running PipelineRunExecutor::execute()
 * with a fake hermes_cli provider and fake command runner, then verifying
 * that ReviewIntelligenceService::analyse() is invoked after the gate passes
 * and before CompletionStateGate promotes completion, and that its verdict
 * is correctly threaded into the completion decision.
 */
final class SeniorCriticTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-critic-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-critic-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M3-001: Critic runs after gate passes and before completion promotion (hermes path).
     *
     * On a hermes run where VerificationGate aggregate = passed,
     * ReviewIntelligenceService::analyse() is invoked exactly once, fed the
     * diff + test evidence, and its verdict reaches CompletionStateGate
     * BEFORE a completed/passed decision.
     */
    public function test_critic_runs_after_gate_passes_before_completion_promotion(): void
    {
        $runId = 'dev-critic-001-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'review', riskLevel: 'R1');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/CleanService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/CleanServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        file_put_contents($target, "<?php\nfinal class CleanService { public function run(): string { return 'ok'; } }\n");
        file_put_contents($testFile, "<?php\ntest_clean();\n");
        $this->git(['add', 'app/CleanService.php', 'tests/Unit/CleanServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/CleanServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $capturingCritic = new CapturingCriticService;

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->criticEnvelope(intent: 'Update CleanService');
        $taskContract = $this->criticTaskContract([
            'allowed_files' => ['app/CleanService.php', 'tests/Unit/CleanServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/CleanServiceTest.php'],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M3-001: critic was invoked exactly once with non-empty changed_files
        $this->assertCount(1, $capturingCritic->capturedInputs,
            'Critic must be invoked exactly once');
        $criticInput = $capturingCritic->capturedInputs[0];
        $changedFiles = $criticInput['changed_files'] ?? [];
        $this->assertNotEmpty($changedFiles,
            'Critic must receive non-empty changed_files');

        // Completion decision observably depends on critic verdict
        // (for a clean diff with matching test, critic returns no_concerns → passed)
        $this->assertContains(
            $result->verificationStatus,
            [VerificationGateResult::STATUS_PASSED, 'passed'],
            'Gate must pass for a clean diff'
        );
        $this->assertNotNull(
            $result->providerCallSummary['critic_status'] ?? null,
            'Critic status must be recorded in the result (proves it ran before completion)'
        );
    }

    /**
     * VAL-M3-002: Blocker-severity finding forces non-completed even when tests are green.
     *
     * Given a diff whose review yields a blocker-severity finding
     * (SEVERITY_BLOCKER → STATUS_ESCALATE) while the gate = passed,
     * the final completion MUST be non-completed.
     */
    public function test_blocker_severity_forces_non_completed_when_tests_green(): void
    {
        // Use the critic directly to verify the wiring logic in CompletionStateGate
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-blocker',
            'changed_files' => ['.env'],  // triggers secret_leak → SEVERITY_BLOCKER
            'diff_chunks' => [['file' => '.env', 'body' => 'DB_PASSWORD=secret123', 'line' => 1]],
            'test_paths' => ['tests/Feature/EnvTest.php'],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $receipt->status,
            'Secret leak in .env must produce STATUS_ESCALATE');

        // Now verify the gate blocks completion
        $decision = $this->decideWithCritic($receipt);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'Blocker finding must force non-completed even when tests are green'
        );
        $this->assertContains('critic_escalate', $decision->honestyFlags,
            'Honesty flag must record the critic escalation');
    }

    /**
     * VAL-M3-003: Critical-severity finding also blocks (escalate, not only blocker).
     *
     * A finding whose highest severity is critical (STATUS_ESCALATE) forces
     * completion to non-completed, identical to blocker.
     */
    public function test_critical_severity_also_blocks_completion(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-critical',
            'changed_files' => ['database/migrations/2026_drop_users.php', 'tests/Feature/MigrationTest.php'],
            'diff_chunks' => [['file' => 'database/migrations/2026_drop_users.php', 'body' => 'DB::statement("DROP TABLE users");', 'line' => 15]],
            'test_paths' => ['tests/Feature/MigrationTest.php'],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $receipt->status,
            'Data-loss keyword must produce STATUS_ESCALATE');

        $decision = $this->decideWithCritic($receipt);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'Critical finding must force non-completed'
        );
    }

    /**
     * VAL-M3-004: Clean diff is NOT falsely blocked (no false positive).
     *
     * A clean diff with green tests and zero findings (STATUS_NO_CONCERNS)
     * leaves completion at passed.
     */
    public function test_clean_diff_not_falsely_blocked(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-clean',
            'changed_files' => ['app/Http/Controllers/HealthzController.php', 'tests/Feature/HealthzControllerTest.php'],
            'diff_chunks' => [['file' => 'app/Http/Controllers/HealthzController.php', 'body' => 'public function index() { return response()->json(["ok" => true]); }', 'line' => 12]],
            'test_paths' => ['tests/Feature/HealthzControllerTest.php'],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_NO_CONCERNS, $receipt->status);

        $decision = $this->decideWithCritic($receipt);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'Clean diff must stay completed (no false positive)'
        );
    }

    /**
     * VAL-M3-005: Critic failure/exception blocks or needs_review — never silently passes.
     *
     * If analyse() throws or returns an unusable receipt, the pipeline MUST
     * NOT promote passed; it degrades to needs_review/blocked with an
     * honesty flag. Never swallowed to green.
     */
    public function test_critic_exception_degrades_to_non_passed_with_honesty_flag(): void
    {
        $decision = $this->decideWithCriticException(
            new \RuntimeException('Critic service unavailable')
        );

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'Critic exception must degrade to non-passed'
        );
        $this->assertContains('critic_failed', $decision->honestyFlags,
            'Honesty flag critic_failed must be present when critic throws');
    }

    /**
     * VAL-M3-006: Insufficient-context never yields a silent "no concerns".
     *
     * When the critic input lacks both changed_files and diff_chunks,
     * the receipt is STATUS_BLOCKED_INSUFFICIENT_CONTEXT with non-empty
     * blocker_reasons, and completion is non-completed.
     */
    public function test_insufficient_context_never_yields_silent_no_concerns(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-empty',
        ]);

        $this->assertSame(ReviewReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT, $receipt->status);
        $this->assertNotEmpty($receipt->blockerReasons);

        $decision = $this->decideWithCritic($receipt);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'blocked_insufficient_context must not promote to passed'
        );
    }

    /**
     * VAL-M3-007: On a real hermes run the critic is fed the actual observed diff (not empty).
     *
     * The critic receives changed_files/diff_chunks derived from
     * $scopeReceipt->observed->fileDiffs, so a real run never trips
     * insufficient-context spuriously.
     */
    public function test_critic_receives_actual_observed_diff_paths(): void
    {
        $runId = 'dev-critic-007-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'review', riskLevel: 'R1');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/RealService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/RealServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        file_put_contents($target, "<?php\nfinal class RealService { public function go(): bool { return true; } }\n");
        file_put_contents($testFile, "<?php\ntest_real();\n");
        $this->git(['add', 'app/RealService.php', 'tests/Unit/RealServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        // Capture the critic input using composition (not extending final class)
        $capturingCritic = new CapturingCriticService;

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/RealServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);

        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->criticEnvelope(intent: 'Update RealService');
        $taskContract = $this->criticTaskContract([
            'allowed_files' => ['app/RealService.php', 'tests/Unit/RealServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/RealServiceTest.php'],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M3-007: critic input changed_files must match the observed diff paths
        $this->assertNotEmpty($capturingCritic->capturedInputs, 'Critic must have been invoked');
        $criticInput = $capturingCritic->capturedInputs[0];
        $changedFiles = $criticInput['changed_files'] ?? [];
        $this->assertNotEmpty($changedFiles, 'Critic must receive non-empty changed_files');
        $this->assertContains('app/RealService.php', $changedFiles,
            'Critic changed_files must contain the observed diff path');
    }

    /**
     * VAL-M3-008: Green-but-obvious-defect diff is rejected (anti-gaming, heuristic-bounded).
     *
     * A diff that passes tests but carries a critic-detectable defect
     * (secret-leak file, data-loss/forbidden/scope-violation heuristic,
     * OR an operator risk_rule of severity blocker/critical) MUST be rejected.
     */
    public function test_green_but_heuristic_detectable_defect_diff_is_rejected(): void
    {
        // Scenario: tests pass, but .env is committed (secret leak → blocker)
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-defect',
            'changed_files' => ['.env', 'app/Config.php', 'tests/Feature/ConfigTest.php'],
            'diff_chunks' => [['file' => 'app/Config.php', 'body' => 'public function get() { return config("app.key"); }', 'line' => 10]],
            'test_paths' => ['tests/Feature/ConfigTest.php'],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $receipt->status);

        $decision = $this->decideWithCritic($receipt);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'Green tests + blocker/critical finding must not be completed'
        );

        // Also test with an operator risk_rule of severity blocker
        $riskRuleReceipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-risk-rule',
            'changed_files' => ['app/Billing/ChargeService.php', 'tests/Feature/Billing/ChargeServiceTest.php'],
            'diff_chunks' => [['file' => 'app/Billing/ChargeService.php', 'body' => 'public function charge() { return 0; }', 'line' => 5]],
            'test_paths' => ['tests/Feature/Billing/ChargeServiceTest.php'],
            'risk_rules' => [[
                'risk_type' => 'regression',
                'severity' => 'blocker',
                'file_glob' => 'app/Billing/*',
                'message' => 'Billing changes require sign-off',
                'remediation' => 'Add finance reviewer to the PR.',
            ]],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $riskRuleReceipt->status);

        $riskRuleDecision = $this->decideWithCritic($riskRuleReceipt);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $riskRuleDecision->status,
            'Operator risk_rule with blocker severity must block completion'
        );
    }

    /**
     * VAL-M3-009: Non-blocking findings surface without false-green and without over-blocking.
     *
     * Findings whose highest severity is high/medium/low (STATUS_REVIEWED)
     * are recorded and route completion to at most needs_review; they do NOT
     * hard-block and do NOT silently disappear into passed.
     */
    public function test_non_blocking_findings_route_to_needs_review(): void
    {
        // A medium-severity finding (test_gap for a file without matching test)
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-reviewed',
            'changed_files' => ['app/NewFeature.php'],
            'diff_chunks' => [['file' => 'app/NewFeature.php', 'body' => 'public function execute() { return true; }', 'line' => 10]],
            'test_paths' => [],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_REVIEWED, $receipt->status);
        $this->assertNotEmpty($receipt->findings);

        $decision = $this->decideWithCritic($receipt);

        // Needs_review, NOT hard-blocked, NOT silently passed
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'Non-blocking findings must route to needs_review (not passed, not blocked)'
        );
        $this->assertContains('critic_reviewed', $decision->honestyFlags);
    }

    /**
     * VAL-CROSS-002: One run chains floor → repair → critic → completion in order.
     *
     * On a single hermes run that fails the gate then converges, the pipeline
     * applies the M1 floor on each attempt, runs the M2 repair loop to green,
     * then runs the M3 critic before completion is promoted.
     */
    public function test_full_chain_floor_repair_critic_completion(): void
    {
        $runId = 'dev-critic-cross-002-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/ChainService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/ChainServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        file_put_contents($target, "<?php\nfinal class ChainService { public function value(): string { return 'before'; } }\n");
        file_put_contents($testFile, "<?php\ntest_chain();\n");
        $this->git(['add', 'app/ChainService.php', 'tests/Unit/ChainServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        // Capture the order of operations
        $capturingCritic = new CapturingCriticService;

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $providerState->critic = $capturingCritic;
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
                // Log provider calls into the critic's operation log
                $this->state->critic->operationLog[] = "provider_call_{$this->state->callCount}";

                if ($this->state->callCount === 1) {
                    // First attempt: broken code
                    file_put_contents($this->target, "<?php\nfinal class ChainService { public function value(): string { return 'broken'; } }\n");
                } else {
                    // Second attempt: fixed code
                    file_put_contents($this->target, "<?php\nfinal class ChainService { public function value(): string { return 'fixed'; } }\n");
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
            command: '/opt/homebrew/bin/php artisan test tests/Unit/ChainServiceTest.php',
            exitCode: 1, stdout: 'FAILURES!', stderr: '', durationMs: 100,
        ));
        // Attempt 2: passes
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/ChainServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);

        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->criticEnvelope(intent: 'Fix ChainService');
        $taskContract = $this->criticTaskContract([
            'allowed_files' => ['app/ChainService.php', 'tests/Unit/ChainServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/ChainServiceTest.php'],
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

        // VAL-CROSS-002: observable order is floor-gate(fail) → repair → floor-gate(pass) → critic → completion
        $operationLog = $capturingCritic->operationLog;
        $this->assertContains('provider_call_1', $operationLog, 'First provider call must happen');
        $this->assertContains('provider_call_2', $operationLog, 'Repair must re-invoke provider');
        $this->assertContains('critic_invoked', $operationLog, 'Critic must be invoked after gate passes');

        // Verify the order: provider_call_1 before provider_call_2 before critic_invoked
        $idx1 = array_search('provider_call_1', $operationLog);
        $idx2 = array_search('provider_call_2', $operationLog);
        $idxCritic = array_search('critic_invoked', $operationLog);
        $this->assertLessThan($idx2, $idx1, 'First provider call must precede repair call');
        $this->assertLessThan($idxCritic, $idx2, 'Repair call must precede critic');

        // Final verification must be passed after repair converges
        $this->assertContains(
            $result->verificationStatus,
            [VerificationGateResult::STATUS_PASSED, 'passed'],
            'Final verification must be passed after repair converges'
        );

        // Critic must have run (completion depends on its verdict)
        $this->assertNotNull(
            $result->providerCallSummary['critic_status'] ?? null,
            'Critic verdict must be recorded in the result'
        );
    }

    /**
     * VAL-M3-001 tightening: critic runs ONLY after a PASSED gate, not merely a
     * not-failed gate (needs_review / skipped must NOT invoke the critic).
     *
     * This locks the contract wording "critic runs exactly once after a PASSED
     * gate": the guard must key on aggregateStatus === STATUS_PASSED, not the
     * broader !== STATUS_FAILED. A needs_review aggregate (doc-only diff with
     * no validationCommands and no no_test_reason) must NOT invoke the critic;
     * a passed aggregate must invoke it exactly once.
     */
    public function test_critic_does_not_run_on_non_passed_gate_but_runs_once_on_passed(): void
    {
        // ---- Scenario A: needs_review gate (non-passed, non-failed) ----
        // A doc-only diff with empty validationCommands and no no_test_reason
        // yields aggregateStatus === needs_review (test_skipped_no_reason).
        // The critic MUST NOT run on this path per the M3 contract.
        $runIdA = 'dev-critic-guard-needs-review-'.bin2hex(random_bytes(3));
        $storageA = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storageA, $runIdA, taskKind: 'review', riskLevel: 'R0');
        $this->initGitWorkspace();

        // Doc-only file (no .php) → M1 floor adds no commands.
        $docTarget = $this->tmpWorkspace.'/docs/README.md';
        mkdir(dirname($docTarget), 0o755, true);
        file_put_contents($docTarget, "# README\nUpdated docs.\n");
        $this->git(['add', 'docs/README.md']);
        $this->git(['commit', '-m', 'doc fixture']);

        $providerStateA = new \stdClass;
        $providerStateA->callCount = 0;
        $fakeHermesA = $this->makeFakeHermesProvider($docTarget, $providerStateA);

        $managerA = app(AiProviderManager::class);
        $managerA->registerDriver('hermes_cli', $fakeHermesA);
        app()->instance(AiProviderManager::class, $managerA);

        $commandRunnerA = new FakeCommandRunner; // no commands queued → empty execution

        $capturingCriticA = new CapturingCriticService;

        $gatewayA = new FakeClaudeCliGateway;
        $containerA = new Container;
        $containerA->instance(ClaudeCliGateway::class, $gatewayA);
        $containerA->instance(VerificationCommandRunner::class, $commandRunnerA);
        $containerA->instance(ReviewIntelligenceService::class, $capturingCriticA);
        $executorA = new PipelineRunExecutor($containerA, $storageA);

        $envelopeA = $this->criticEnvelope(intent: 'Update docs only');
        $taskContractA = $this->criticTaskContract([
            'allowed_files' => ['docs/README.md'],
            'validation_commands' => [], // empty caller list
            // no no_test_reason → needs_review honesty path
        ]);

        $resultA = $executorA->execute(
            envelope: $envelopeA,
            taskContract: $taskContractA,
            promptProjection: $this->buildSendableProjection(envelope: $envelopeA, taskContract: $taskContractA),
            runId: $runIdA,
        );

        // Lock that the gate aggregate is needs_review (the non-passed, non-failed state).
        $this->assertSame(
            VerificationGateResult::STATUS_NEEDS_REVIEW,
            $resultA->verificationStatus,
            'Doc-only diff with no commands and no reason must yield needs_review'
        );

        // Lock that the critic was NOT invoked on the non-passed gate.
        $this->assertCount(
            0, $capturingCriticA->capturedInputs,
            'Critic must NOT run when aggregateStatus is needs_review (non-passed), per VAL-M3-001'
        );
        $this->assertNull(
            $resultA->providerCallSummary['critic_status'] ?? null,
            'Critic status must be null when the critic did not run (needs_review gate)'
        );

        // ---- Scenario B: passed gate → critic runs exactly once ----
        // Reuse the proven clean-diff path: a .php file with a matching test
        // that passes yields aggregateStatus === passed, and the critic MUST
        // be invoked exactly once.
        $runIdB = 'dev-critic-guard-passed-'.bin2hex(random_bytes(3));
        $storageB = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storageB, $runIdB, taskKind: 'review', riskLevel: 'R1');
        // Fresh workspace to avoid cross-scenario git state.
        $workspaceB = sys_get_temp_dir().'/atlas-dev-critic-guard-passed-'.bin2hex(random_bytes(4));
        mkdir($workspaceB, 0o755, true);
        try {
            $gitB = function (array $args) use ($workspaceB): void {
                $p = new Process(['git', ...$args], $workspaceB, null, null, 10.0);
                $p->run();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            };
            $gitB(['init', '-q']);
            $gitB(['config', 'user.email', 'atlas-test@example.local']);
            $gitB(['config', 'user.name', 'Atlas Test']);

            $phpTarget = $workspaceB.'/app/GuardedService.php';
            $phpTest = $workspaceB.'/tests/Unit/GuardedServiceTest.php';
            mkdir(dirname($phpTarget), 0o755, true);
            mkdir(dirname($phpTest), 0o755, true);
            file_put_contents($phpTarget, "<?php\nfinal class GuardedService { public function run(): string { return 'ok'; } }\n");
            file_put_contents($phpTest, "<?php\ntest_guarded();\n");
            $gitB(['add', 'app/GuardedService.php', 'tests/Unit/GuardedServiceTest.php']);
            $gitB(['commit', '-m', 'php fixture']);

            $providerStateB = new \stdClass;
            $providerStateB->callCount = 0;
            $fakeHermesB = $this->makeFakeHermesProvider($phpTarget, $providerStateB);

            $managerB = app(AiProviderManager::class);
            $managerB->registerDriver('hermes_cli', $fakeHermesB);
            app()->instance(AiProviderManager::class, $managerB);

            $commandRunnerB = new FakeCommandRunner;
            $commandRunnerB->queue(new VerificationCommandResult(
                command: '/opt/homebrew/bin/php artisan test tests/Unit/GuardedServiceTest.php',
                exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
            ));

            $capturingCriticB = new CapturingCriticService;

            $gatewayB = new FakeClaudeCliGateway;
            $containerB = new Container;
            $containerB->instance(ClaudeCliGateway::class, $gatewayB);
            $containerB->instance(VerificationCommandRunner::class, $commandRunnerB);
            $containerB->instance(ReviewIntelligenceService::class, $capturingCriticB);

            // Use a dedicated envelope pointing at workspace B.
            $envelopeB = new OperationEnvelope(
                runId: 'unused-by-executor',
                surfaceId: 'atlas_desktop_ai',
                surfaceContext: new SurfaceContext(
                    productSurface: 'atlas_ai_desktop_mac',
                    composerMode: 'programming',
                    composerTask: 'dev',
                    providerChoice: 'hermes_cli',
                ),
                workspace: $workspaceB,
                workspaceHash: hash('sha256', $workspaceB),
                gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
                rawIntent: 'Update GuardedService',
                normalizedIntent: 'Update GuardedService',
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
            $taskContractB = $this->criticTaskContract([
                'allowed_files' => ['app/GuardedService.php', 'tests/Unit/GuardedServiceTest.php'],
                'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/GuardedServiceTest.php'],
            ]);

            $executorB = new PipelineRunExecutor($containerB, $storageB);

            $resultB = $executorB->execute(
                envelope: $envelopeB,
                taskContract: $taskContractB,
                promptProjection: $this->buildSendableProjection(envelope: $envelopeB, taskContract: $taskContractB),
                runId: $runIdB,
            );

            // Lock that the gate aggregate is passed.
            $this->assertContains(
                $resultB->verificationStatus,
                [VerificationGateResult::STATUS_PASSED, 'passed'],
                'PHP diff with a passing test must yield a passed gate'
            );

            // Lock that the critic ran EXACTLY once on the passed gate.
            $this->assertCount(
                1, $capturingCriticB->capturedInputs,
                'Critic must run exactly once when aggregateStatus is passed (VAL-M3-001)'
            );
            $this->assertNotNull(
                $resultB->providerCallSummary['critic_status'] ?? null,
                'Critic status must be recorded when the critic ran on a passed gate'
            );
        } finally {
            $this->rmrf($workspaceB);
        }
    }

    // ------------------------------------------------------------------
    // Helper: CompletionStateGate with a green-verification scenario + critic
    // ------------------------------------------------------------------

    /**
     * Creates a CompletionStateGate decision with the given critic receipt,
     * simulating a scenario where all other checks pass (green verification).
     */
    private function decideWithCritic(ReviewReceipt $receipt): CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $this->criticTaskContract(),
            scopeReceipt: $this->greenScopeReceipt(),
            verificationResult: $this->greenVerificationResult(),
            callResult: $this->greenCallResult(),
            diffResult: DiffParseResult::patch('--- a/app/Foo.php
+++ b/app/Foo.php
@@ -1,1 +1,1 @@
-return "old";
+return "new";
', ['app/Foo.php']),
            reviewReceipt: $receipt,
        );
    }

    /**
     * Creates a CompletionStateGate decision when the critic throws.
     */
    private function decideWithCriticException(\Throwable $exception): CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $this->criticTaskContract(),
            scopeReceipt: $this->greenScopeReceipt(),
            verificationResult: $this->greenVerificationResult(),
            callResult: $this->greenCallResult(),
            diffResult: DiffParseResult::patch('--- a/app/Foo.php
+++ b/app/Foo.php
@@ -1,1 +1,1 @@
-return "old";
+return "new";
', ['app/Foo.php']),
            criticException: $exception,
        );
    }

    private function greenCallResult(): ProviderCallResult
    {
        return ProviderCallResult::fromStdout(
            runId: 'run-critic-test',
            actualProvider: 'hermes_cli',
            actualModelFamily: 'minimax-m3',
            exitStatus: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 100,
        );
    }

    private function greenScopeReceipt(): ScopeGuardReceipt
    {
        $observed = new ScopeObserved(
            gitDiffHash: hash('sha256', 'test-diff'),
            changedFiles: ['app/Foo.php'],
            changedFilesCount: 1,
            fileDiffs: [new ScopeFileDiff(
                path: 'app/Foo.php',
                added: 1,
                removed: 1,
                fileHashAfter: hash('sha256', 'new'),
            )],
        );

        return ScopeGuardReceipt::issue(
            runId: 'run-critic-test',
            taskContractHash: 'test-hash',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: $observed,
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Foo.php'],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 10,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }

    private function greenVerificationResult(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
            profile: 'tests_passed',
        );
    }

    // ------------------------------------------------------------------
    // Helpers (shared with RepairToGreenTest pattern)
    // ------------------------------------------------------------------

    private function makeFakeHermesProvider(string $target, object $providerState): AiProvider
    {
        return new class($target, $providerState) implements AiProvider
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
                file_put_contents($this->target, "<?php\nfinal class CleanService { public function run(): string { return 'updated'; } }\n");

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

    private function criticEnvelope(?string $intent = null, ?string $providerChoice = null): OperationEnvelope
    {
        $intent ??= 'Update service code';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: $providerChoice ?? 'hermes_cli',
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

    private function criticTaskContract(array $overrides = []): LightTaskContract
    {
        return $this->taskContractFixture(array_merge([
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
        ], $overrides));
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
