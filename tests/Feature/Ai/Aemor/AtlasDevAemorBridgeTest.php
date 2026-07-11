<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * OUTC-01(a): Dev pipeline records AEMOR episode+outcome and fans out to ai_run_outcomes.
 */
final class AtlasDevAemorBridgeTest extends TestCase
{
    use AtlasDevProviderFixtures;
    use CreatesAemorTables;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();

        config()->set('atlas_dev.elevations.e3.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');
        config()->set('atlas_dev.elevations.e5.mode', 'off');
        config()->set('atlas_dev.elevations.e6.mode', 'off');
        config()->set('atlas_dev.elevations.weak_output.mode', 'off');
        config()->set('atlas.programming.sovereign_floor_enforced', false);
        config()->set('atlas.programming.strict_retrieval_gate', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-aemor-'.bin2hex(random_bytes(4));
        $this->tmpWorkspace = $this->tmpStorage.'/workspace';
        mkdir($this->tmpStorage, 0o755, true);
        mkdir($this->tmpWorkspace, 0o755, true);

        config()->set('atlas.aemor.engineering_outcome_enabled', true);
        config()->set('atlas.aemor.engineering_outcome_mode', 'default');

        $this->bindAwisGate();

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpStorage.'/live_outcomes.jsonl');
        app()->instance(AtlasDecideLiveOutcomeFeedbackService::class, $feedback);
        app()->instance(AtlasEngineeringOutcomeRecorder::class, app(AtlasEngineeringOutcomeRecorder::class));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_run_outcomes');
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAemorTables();
        app()->forgetInstance(AtlasDecideLiveOutcomeFeedbackService::class);
        app()->forgetInstance(AtlasEngineeringOutcomeRecorder::class);
        parent::tearDown();
    }

    public function test_passing_dev_run_records_aemor_episode_and_ai_run_outcome(): void
    {
        $runId = 'dev-aemor-pass-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php';
        @mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nassert(false);\n");

        $diff = <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);
DIFF;

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->devEnvelope();
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

        $this->assertSame('passed', $result->completionState);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
        $this->assertSame('succeeded', DB::table('atlas_aemor_outcomes')->value('status'));
        $this->assertSame(1, DB::table('ai_run_outcomes')->count());
        $this->assertSame('atlas_dev', DB::table('ai_run_outcomes')->value('flow_id'));
    }

    public function test_failing_dev_run_records_failed_aemor_outcome(): void
    {
        $storage = new ReceiptStorage($this->tmpStorage);
        $runId = 'dev-aemor-fail-'.bin2hex(random_bytes(3));
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: <<<'DIFF'
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,2 @@
 <?php
-assert(false);
+assert(true);
DIFF));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'vendor/bin/phpunit',
            exitCode: 1,
            stdout: 'FAILURES!',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $this->bindNoConcernsCritic($container);
        $executor = new PipelineRunExecutor($container, $storage);

        @mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php', "<?php\nassert(false);\n");

        $envelope = $this->devEnvelope();
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/Services/Foo/FooServiceTest.php'],
        ]);
        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $this->assertSame(CompletionSummary::STATUS_FAILED, $result->completionState);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertSame('failed', DB::table('atlas_aemor_outcomes')->value('status'));
    }

    private function bindAwisGate(): void
    {
        $this->app->instance(AtlasWorkspaceIntelligenceExecutionGateService::class, new class
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'allowed' => true,
                    'status' => 'ready',
                    'mode' => $mode,
                    'blockers' => [],
                ];
            }
        });
    }

    private function devEnvelope(): OperationEnvelope
    {
        $intent = 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php';

        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: null,
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

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $this->bindNoConcernsCritic($container);

        return new PipelineRunExecutor($container, $storage);
    }
}
