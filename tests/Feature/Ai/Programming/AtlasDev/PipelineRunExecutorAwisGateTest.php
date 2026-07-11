<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

final class PipelineRunExecutorAwisGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6'] as $elevation) {
            config()->set('atlas_dev.elevations.'.$elevation.'.mode', 'off');
        }
        config()->set('atlas.programming.strict_retrieval_gate', false);

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-awis-gate-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-awis-gate-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpStorage);
        File::deleteDirectory($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_blocks_when_awis_workspace_is_not_certified(): void
    {
        $this->bindAwisGate(new class
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'allowed' => false,
                    'status' => 'blocked',
                    'mode' => $mode,
                    'blockers' => ['workspace_not_ready'],
                ];
            }
        });

        $result = $this->executeRun();

        $this->assertSame('blocked', $result->completionState);
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertContains('workspace_not_ready', $result->providerCallSummary['error_codes']);
    }

    public function test_proceeds_when_awis_workspace_is_certified(): void
    {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: 'no_patch_needed: true'));

        $this->bindAwisGate(new class
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

        $result = $this->executeRun(gateway: $gateway);

        $this->assertNotContains('awis_execution_gate_blocked', $result->providerCallSummary['error_codes']);
        $this->assertNotContains('awis_execution_gate_failed_closed', $result->providerCallSummary['error_codes']);
        $this->assertNotSame('blocked', $result->completionState);
        $this->assertGreaterThanOrEqual(1, $result->providerCallSummary['provider_calls']);
        $this->assertCount(1, $gateway->requests);
    }

    public function test_blocks_fail_closed_when_awis_gate_throws(): void
    {
        $this->bindAwisGate(new class
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                throw new RuntimeException('awis runtime unavailable');
            }
        });

        $result = $this->executeRun();

        $this->assertSame('blocked', $result->completionState);
        $this->assertSame(0, $result->providerCallSummary['provider_calls']);
        $this->assertContains('awis_execution_gate_failed_closed', $result->providerCallSummary['error_codes']);
    }

    private function bindAwisGate(object $gate): void
    {
        $this->app->instance(AtlasWorkspaceIntelligenceExecutionGateService::class, $gate);
    }

    private function executeRun(?FakeClaudeCliGateway $gateway = null): \App\Http\Controllers\AtlasDev\Support\RunExecutionResult
    {
        $runId = 'dev-awis-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $envelope = OperationEnvelope::fromArray(array_replace(
            $this->envelopeFixture()->toCanonicalArray(),
            ['workspace' => $this->tmpWorkspace, 'workspace_hash' => hash('sha256', $this->tmpWorkspace)],
        ));
        $taskContract = $this->taskContractFixture();
        $promptProjection = $this->buildSendableProjection(envelope: $envelope);

        $gateway ??= new FakeClaudeCliGateway;
        if ($gateway->requests === []) {
            $gateway->queue($this->gatewayResponse(stdout: 'no_patch_needed: true'));
        }

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

        $executor = new PipelineRunExecutor($container, $storage);

        return $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            runId: $runId,
            expectedCompactSddHash: null,
        );
    }
}
