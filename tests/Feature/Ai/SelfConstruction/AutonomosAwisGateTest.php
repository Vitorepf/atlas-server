<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use RuntimeException;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * ENG-06 — AWIS gate blocks mutative Autonomos task serving before claim/serving.
 */
final class AutonomosAwisGateTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/atlas-awis-gate-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        \App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        \App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_blocks_when_workspace_is_not_certified(): void
    {
        $gate = new class implements AwisExecutionGatePort
        {
            public int $calls = 0;

            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                $this->calls++;

                return [
                    'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                    'allowed' => false,
                    'status' => 'blocked',
                    'mode' => $mode,
                    'blockers' => ['workspace_not_ready'],
                ];
            }
        };

        $serving = new AtlasTaskServingService($this->orchestrator(), awisGate: $gate);
        $result = $serving->next('worker-awis-blocked');

        $this->assertSame('awis_execution_blocked', $result['status']);
        $this->assertTrue($result['give_back']);
        $this->assertSame('awis_workspace_not_certified_for_task_serving', $result['reason']);
        $this->assertContains('workspace_not_ready', (array) data_get($result, 'awis_execution_gate.blockers'));
        $this->assertSame(1, $gate->calls, 'gate result is cached per service instance');
        $serving->next('worker-awis-blocked');
        $this->assertSame(1, $gate->calls);
    }

    public function test_blocks_fail_closed_when_awis_gate_throws(): void
    {
        $gate = new class implements AwisExecutionGatePort
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                throw new RuntimeException('awis runtime unavailable');
            }
        };

        $result = (new AtlasTaskServingService($this->orchestrator(), awisGate: $gate))->next('worker-awis-throw');

        $this->assertSame('awis_execution_blocked', $result['status']);
        $this->assertTrue($result['give_back']);
        $this->assertContains('awis_execution_gate_failed_closed', (array) data_get($result, 'awis_execution_gate.blockers'));
    }

    public function test_proceeds_when_awis_workspace_is_certified(): void
    {
        $gate = new class implements AwisExecutionGatePort
        {
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                    'allowed' => true,
                    'status' => 'ready',
                    'mode' => $mode,
                    'blockers' => [],
                ];
            }
        };

        $result = (new AtlasTaskServingService($this->orchestrator(), awisGate: $gate))->next('worker-awis-ready');

        $this->assertNotSame('awis_execution_blocked', $result['status']);
        $this->assertContains($result['status'], ['served', 'no_claimable_task', 'waiting_on_dependencies']);
    }

    public function test_serving_service_wires_awis_gate_service(): void
    {
        $source = file_get_contents(base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php'));
        $this->assertIsString($source);
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($source, 'AtlasWorkspaceIntelligenceExecutionGateService'),
        );
    }
}
