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
use App\Services\Ai\Programming\AtlasDev\Intelligence\IntentJudgeOutcome;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E1 — Semantic-critic `detectIntentFalsification` end-to-end integration.
 *
 * Covers VAL-E1-009, VAL-E1-010, VAL-E1-011 at the pipeline-integration
 * level: PipelineRunExecutor threads the E2-established intent basis
 * (intentVerbs + intentText + diff) into the critic input, the critic's
 * detectIntentFalsification() detector fires a finding on a green-gate-but-
 * misses-intent diff (VAL-E1-009), the layer never clears the post-gate
 * honesty flag (VAL-E1-010), and the optional LLM-judge sub-layer (sub-flag
 * + container binding) can only add doubt/escalate — a fake judge screaming
 * APPROVE cannot remove the deterministic finding (VAL-E1-011).
 *
 * These tests exercise the full pipeline through the fake-provider harness
 * (FakeClaudeCliGateway + FakeCommandRunner) and assert the verdict via the
 * captured ReviewReceipt (composition wrapper around the real
 * ReviewIntelligenceService). No real LLM key is required.
 */
final class IntentFalsificationCriticIntegrationTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);
        // E1 must be on for the critic detector to consult the basis. The
        // critic detector itself runs whenever intent_basis is carried and
        // the verbs are non-empty (independent of e1.mode), but enabling e1
        // keeps the post-gate probe + critic in lockstep.
        config()->set('atlas_dev.elevations.e1.mode', 'advisory');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e1-critic-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e1-critic-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-E1-009 (integration): a green-gate diff that misses the intent
     * yields a critic ReviewReceipt.findings[] containing a
     * detectIntentFalsification finding (riskType bug/reliability, non-empty
     * description referencing the intent).
     *
     * The green gate routes through the M3 critic block (aggregateStatus ===
     * STATUS_PASSED), the executor threads the intent basis into the critic
     * input, and the detector emits its finding.
     */
    public function test_val_e1_009_green_gate_intent_missing_diff_emits_critic_finding_via_executor(): void
    {
        $runId = 'dev-e1-critic-009-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'review', riskLevel: 'R1');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/RateLimitService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/RateLimitServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        // Base content; the fake provider will rewrite it with an
        // intent-missing diff (a return 42 that does NOT implement 'fix').
        file_put_contents($target, "<?php\nfinal class RateLimitService { public function check(): bool { return false; } }\n");
        file_put_contents($testFile, "<?php\ntest_rate_limit();\n");
        $this->git(['add', 'app/RateLimitService.php', 'tests/Unit/RateLimitServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, intentImplementing: false);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/RateLimitServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $capturingCritic = new CapturingCriticServiceE1;

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->e1Envelope(intent: 'Fix the rate-limit guard so anonymous users are blocked');
        $taskContract = $this->e1TaskContract([
            'allowed_files' => ['app/RateLimitService.php', 'tests/Unit/RateLimitServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/RateLimitServiceTest.php'],
        ], intentVerbs: ['corrigir']);

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // The critic was invoked and received the intent basis.
        $this->assertNotEmpty($capturingCritic->capturedInputs, 'critic must be invoked');
        $criticInput = $capturingCritic->capturedInputs[0];
        $this->assertArrayHasKey(
            'intent_basis',
            $criticInput,
            'VAL-E1-009: executor threads intent_basis into the critic input',
        );
        $this->assertSame(
            ['corrigir'],
            $criticInput['intent_basis']['intent_verbs'],
            'VAL-E1-009: intent_verbs carried on the basis',
        );

        // The critic receipt contains a detectIntentFalsification finding.
        $receipt = $capturingCritic->lastReceipt;
        $this->assertNotNull($receipt);
        $intentFindings = array_values(array_filter(
            $receipt->findings,
            static fn (ReviewFinding $f): bool => in_array($f->riskType, [ReviewFinding::RISK_BUG, ReviewFinding::RISK_RELIABILITY], true)
                && (str_contains(strtolower($f->title.' '.$f->description), 'intent')
                    || str_contains(strtolower($f->title.' '.$f->description), 'addressed')),
        ));
        $this->assertNotEmpty(
            $intentFindings,
            'VAL-E1-009: detectIntentFalsification finding present in the critic receipt',
        );
    }

    /**
     * VAL-E1-010 (integration): the critic detector's finding escalates the
     * receipt status (never STATUS_NO_CONCERNS over an intent miss); the
     * post-gate honesty flag (set BEFORE the critic runs) is preserved into
     * the completion decision.
     */
    public function test_val_e1_010_critic_detector_only_escalates_and_gate_flag_preserved(): void
    {
        $runId = 'dev-e1-critic-010-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'review', riskLevel: 'R1');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/GuardService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/GuardServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        file_put_contents($target, "<?php\nfinal class GuardService { public function ok(): bool { return true; } }\n");
        file_put_contents($testFile, "<?php\ntest_guard();\n");
        $this->git(['add', 'app/GuardService.php', 'tests/Unit/GuardServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, intentImplementing: false);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/GuardServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        $capturingCritic = new CapturingCriticServiceE1;
        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->e1Envelope(intent: 'Fix the broken guard so it returns false on abuse');
        $taskContract = $this->e1TaskContract([
            'allowed_files' => ['app/GuardService.php', 'tests/Unit/GuardServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/GuardServiceTest.php'],
        ], intentVerbs: ['corrigir']);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $receipt = $capturingCritic->lastReceipt;
        $this->assertNotNull($receipt);
        $this->assertNotSame(
            ReviewReceipt::STATUS_NO_CONCERNS,
            $receipt->status,
            'VAL-E1-010: critic detector escalates the receipt (never STATUS_NO_CONCERNS over an intent miss)',
        );

        // The post-gate honesty flag set BEFORE the critic runs is preserved:
        // the completion can never be passed while the flag is present (the
        // passed-forbids-flags invariant is the mechanical floor). The critic
        // has no surface to clear it.
        $this->assertNotSame(
            'passed',
            $result->completionState,
            'VAL-E1-010: completion is not passed while the intent flag is set',
        );
    }

    /**
     * VAL-E1-011 (integration): with the LLM-judge sub-flag ON and a fake
     * judge bound to the container that attempts to APPROVE, the
     * deterministic intent-falsification finding still appears in the critic
     * receipt. The judge is invoked but its APPROVE outcome is ignored.
     */
    public function test_val_e1_011_fake_judge_approve_cannot_remove_deterministic_finding(): void
    {
        config()->set('atlas_dev.elevations.e1.llm_judge', true);

        $runId = 'dev-e1-critic-011-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'review', riskLevel: 'R1');
        $this->initGitWorkspace();

        $target = $this->tmpWorkspace.'/app/JudgeService.php';
        $testFile = $this->tmpWorkspace.'/tests/Unit/JudgeServiceTest.php';
        mkdir(dirname($target), 0o755, true);
        mkdir(dirname($testFile), 0o755, true);
        file_put_contents($target, "<?php\nfinal class JudgeService { public function go(): bool { return true; } }\n");
        file_put_contents($testFile, "<?php\ntest_judge();\n");
        $this->git(['add', 'app/JudgeService.php', 'tests/Unit/JudgeServiceTest.php']);
        $this->git(['commit', '-m', 'fixture']);

        $providerState = new \stdClass;
        $providerState->callCount = 0;
        $fakeHermes = $this->makeFakeHermesProvider($target, $providerState, intentImplementing: false);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fakeHermes);
        app()->instance(AiProviderManager::class, $manager);

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test tests/Unit/JudgeServiceTest.php',
            exitCode: 0, stdout: 'OK', stderr: '', durationMs: 80,
        ));

        // Real ReviewIntelligenceService so the LLM-judge options thread
        // through analyse(). Capture via a composition wrapper that records
        // the receipt and delegates WITH the options.
        $judge = new class
        {
            public int $invocations = 0;

            public function __invoke(array $context): IntentJudgeOutcome
            {
                $this->invocations++;

                return IntentJudgeOutcome::approve('judge claims the intent is fully addressed');
            }
        };
        // Wrap so we can capture the receipt AND delegate analyse($input, $options).
        $capturingCritic = new class
        {
            public array $capturedInputs = [];

            public ?ReviewReceipt $lastReceipt = null;

            private ReviewIntelligenceService $inner;

            public function __construct()
            {
                $this->inner = new ReviewIntelligenceService;
            }

            public function analyse(array $input, array $options = []): ReviewReceipt
            {
                $this->capturedInputs[] = $input;
                $this->lastReceipt = $this->inner->analyse($input, $options);

                return $this->lastReceipt;
            }
        };

        $gateway = new FakeClaudeCliGateway;
        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ReviewIntelligenceService::class, $capturingCritic);
        $container->bind('atlas_dev.e1.intent_judge', fn () => $judge);
        $executor = new PipelineRunExecutor($container, $storage);

        $envelope = $this->e1Envelope(intent: 'Fix the off-by-one bug in the judge service');
        $taskContract = $this->e1TaskContract([
            'allowed_files' => ['app/JudgeService.php', 'tests/Unit/JudgeServiceTest.php'],
            'validation_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/JudgeServiceTest.php'],
        ], intentVerbs: ['corrigir']);

        $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // The judge was invoked (sub-flag on + binding present).
        $this->assertGreaterThanOrEqual(
            1,
            $judge->invocations,
            'VAL-E1-011: LLM-judge invoked when sub-flag on + binding present',
        );

        // The deterministic finding PERSISTS despite the judge screaming APPROVE.
        $receipt = $capturingCritic->lastReceipt;
        $this->assertNotNull($receipt);
        $intentFindings = array_values(array_filter(
            $receipt->findings,
            static fn (ReviewFinding $f): bool => str_contains(strtolower($f->title.' '.$f->description), 'intent')
                || str_contains(strtolower($f->title.' '.$f->description), 'addressed'),
        ));
        $this->assertNotEmpty(
            $intentFindings,
            'VAL-E1-011: deterministic finding persists despite the judge attempting to approve',
        );
        // No finding asserts approval/clear semantics.
        foreach ($receipt->findings as $f) {
            $this->assertDoesNotMatchRegularExpression(
                '/(intent (is )?(addressed|approved|resolved)|no concern)/i',
                $f->description,
                'VAL-E1-011: no finding may assert approval/clear semantics',
            );
        }
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $overrides
     * @param  list<string>  $intentVerbs
     */
    private function e1TaskContract(array $overrides = [], array $intentVerbs = []): LightTaskContract
    {
        // Build via the fixture (loads the canonical valid contract JSON)
        // with hermes_cli provider lock + repair-disabled, then rebuild with
        // the E1 intent basis so the executor threads the verbs into the
        // critic input. Mirrors IntentLikelyNotAddressedFlagTest / SeniorCriticTest.
        $base = $this->taskContractFixture(array_merge([
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

        return new LightTaskContract(
            runId: $base->runId,
            taskId: $base->taskId,
            specHash: $base->specHash,
            allowedTools: $base->allowedTools,
            blockedActions: $base->blockedActions,
            allowedFiles: $base->allowedFiles,
            watchedFiles: $base->watchedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            maxFilesChanged: $base->maxFilesChanged,
            validationCommands: $base->validationCommands,
            evidenceRequired: $base->evidenceRequired,
            repairPolicy: $base->repairPolicy,
            escalationOn: $base->escalationOn,
            providerLock: $base->providerLock,
            taskContractHash: $base->taskContractHash,
            noTestReason: $base->noTestReason,
            intentText: 'Fix the declared intent',
            intentVerbs: $intentVerbs,
        );
    }

    private function e1Envelope(string $intent): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: 'hermes_cli',
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

    private function makeFakeHermesProvider(string $target, object $providerState, bool $intentImplementing): AiProvider
    {
        return new class($target, $providerState, $intentImplementing) implements AiProvider
        {
            public function __construct(
                private readonly string $target,
                private readonly object $state,
                private readonly bool $intentImplementing,
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
                // intentImplementing=false => write a diff that does NOT
                // implement the verb (no 'fix'/'corrigir' surface form, no
                // intent subject overlap). This is the green-gate-but-misses-
                // intent scenario.
                $body = $this->intentImplementing
                    ? "<?php\nfinal class CleanService { public function run(): string { return 'fixed'; } }\n"
                    : "<?php\nfinal class CleanService { public function run(): string { return 'updated'; } }\n";
                file_put_contents($this->target, $body);

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

/**
 * Composition wrapper around the real ReviewIntelligenceService that records
 * every input + the last receipt. The real service is final, so composition
 * (not inheritance) is used (mirrors SeniorCriticTest's CapturingCriticService
 * pattern, extended to thread the optional $options argument).
 */
final class CapturingCriticServiceE1
{
    /** @var list<array<string,mixed>> */
    public array $capturedInputs = [];

    public ?ReviewReceipt $lastReceipt = null;

    private ReviewIntelligenceService $inner;

    public function __construct()
    {
        $this->inner = new ReviewIntelligenceService;
    }

    public function analyse(array $input, array $options = []): ReviewReceipt
    {
        $this->capturedInputs[] = $input;
        $this->lastReceipt = $this->inner->analyse($input, $options);

        return $this->lastReceipt;
    }
}
