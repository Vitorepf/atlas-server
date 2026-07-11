<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Container\Container;
use Tests\Feature\Ai\Programming\AtlasDev\Http\AtlasDevHttpTestCase;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeRunExecutor;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * ENG-09 — both Dev run paths exercise the same behavioral gate seams.
 */
final class DevPipelineGateParityTest extends AtlasDevHttpTestCase
{
    use \Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6'] as $elevation) {
            config()->set('atlas_dev.elevations.'.$elevation.'.mode', 'off');
        }
        config()->set('atlas_dev.elevations.weak_output.mode', 'off');
        config()->set('atlas.programming.sovereign_floor_enforced', false);
        config()->set('atlas.programming.strict_retrieval_gate', false);
    }

    public function test_legacy_run_controller_invokes_awis_governance_and_live_outcome_feedback(): void
    {
        $plan = $this->plan();
        $awis = new DevPipelineGateParityAwisSpy;
        $governance = new DevPipelineGateParityGovernanceSpy;
        $live = new DevPipelineGateParityLiveOutcomeSpy;

        $this->app->instance(RunExecutor::class, FakeRunExecutor::passing());
        $this->app->instance(AtlasWorkspaceIntelligenceExecutionGateService::class, $awis);
        $this->app->instance(ProviderGovernanceConsult::class, $governance);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $live);

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $awis->calls, 'RunController must consult AWIS before provider execution.');
        $this->assertGreaterThanOrEqual(1, $governance->calls, 'RunController legacy path must consult ProviderGovernanceConsult.');
        $this->assertCount(1, $live->records, 'RunController legacy path must record live outcome feedback.');
        $this->assertTrue($live->records[0]['proven_real']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS, $live->records[0]['result']);
    }

    public function test_pipeline_run_executor_invokes_awis_governance_and_live_outcome_feedback(): void
    {
        $awis = new DevPipelineGateParityAwisSpy;
        $governance = new DevPipelineGateParityGovernanceSpy;
        $live = new DevPipelineGateParityLiveOutcomeSpy;
        $this->app->instance(AtlasWorkspaceIntelligenceExecutionGateService::class, $awis);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $live);

        $storage = new ReceiptStorage($this->tmpStorage);
        $runId = 'dev-parity-'.bin2hex(random_bytes(3));
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        @mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php', "<?php\nassert(false);\n");

        $envelope = OperationEnvelope::fromArray(array_replace(
            $this->envelopeFixture()->toCanonicalArray(),
            ['workspace' => $this->tmpWorkspace, 'workspace_hash' => hash('sha256', $this->tmpWorkspace)],
        ));
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/Services/Foo/FooServiceTest.php'],
        ]);

        $executor = new PipelineRunExecutor(
            $this->pipelineContainer($governance),
            $storage,
        );
        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        $receiptPayload = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertSame('passed', $result->completionState, json_encode([
            'provider_call' => $result->providerCallSummary,
            'completion' => data_get($receiptPayload, 'completion'),
            'scope_guard' => data_get($receiptPayload, 'scope_guard'),
            'verification' => data_get($receiptPayload, 'verification'),
        ], JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(1, $awis->calls, 'PipelineRunExecutor must consult AWIS.');
        $this->assertGreaterThanOrEqual(1, $governance->calls, 'PipelineRunExecutor must consult ProviderGovernanceConsult.');
        $this->assertCount(1, $live->records, 'PipelineRunExecutor must record live outcome feedback.');
        $this->assertTrue($live->records[0]['proven_real']);
        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS, $live->records[0]['result']);
    }

    /**
     * @return array{run_id:string,task_contract_hash:string,confirmation_token:string}
     */
    private function plan(): array
    {
        $data = $this->postPlan($this->defaultRepairPayload());

        return [
            'run_id' => $data['run_id'],
            'task_contract_hash' => $data['hashes']['task_contract'],
            'confirmation_token' => $data['confirmation']['token'],
        ];
    }

    private function pipelineContainer(DevPipelineGateParityGovernanceSpy $governance): Container
    {
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
            exitCode: 0,
            stdout: 'OK (1 test, 1 assertion)',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance(ProviderGovernanceConsult::class, $governance);
        $this->bindNoConcernsCritic($container);

        return $container;
    }
}

final class DevPipelineGateParityAwisSpy
{
    public int $calls = 0;

    public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
    {
        $this->calls++;

        return [
            'allowed' => true,
            'status' => 'ready',
            'mode' => $mode,
            'blockers' => [],
        ];
    }
}

final class DevPipelineGateParityGovernanceSpy
{
    public int $calls = 0;

    /** @var list<array<string,mixed>> */
    public array $contexts = [];

    public function consultBeforeSpawn(array $ctx): array
    {
        $this->calls++;
        $this->contexts[] = $ctx;

        return [
            'provider' => (string) ($ctx['provider'] ?? 'unknown'),
            'surface' => (string) ($ctx['surface'] ?? 'unknown'),
            'should_block' => false,
            'reason' => null,
        ];
    }
}

final class DevPipelineGateParityLiveOutcomeSpy
{
    /** @var list<array<string,mixed>> */
    public array $records = [];

    public function record(array $input): array
    {
        $this->records[] = $input;

        return $input + ['schema_version' => AtlasDecideLiveOutcomeFeedbackService::OUTCOME_SCHEMA];
    }
}
