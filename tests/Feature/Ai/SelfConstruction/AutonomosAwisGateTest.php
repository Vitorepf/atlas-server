<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
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
        AtlasTaskServingSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_rechecks_awis_before_each_next_and_blocks_when_certification_is_revoked(): void
    {
        $gate = new class implements AwisExecutionGatePort
        {
            public int $calls = 0;

            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                $this->calls++;

                return [
                    'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                    'allowed' => $this->calls === 1,
                    'status' => $this->calls === 1 ? 'ready' : 'blocked',
                    'mode' => $mode,
                    'blockers' => $this->calls === 1 ? [] : ['workspace_certification_revoked'],
                ];
            }
        };

        $serving = new AtlasTaskServingService($this->orchestrator(), awisGate: $gate);
        $first = $serving->next('worker-awis-revoked');
        $this->assertNotSame('awis_execution_blocked', $first['status']);
        $this->assertSame(1, $gate->calls);

        $result = $serving->next('worker-awis-revoked');

        $this->assertSame('awis_execution_blocked', $result['status']);
        $this->assertTrue($result['give_back']);
        $this->assertSame('awis_workspace_not_certified_for_task_serving', $result['reason']);
        $this->assertContains('workspace_certification_revoked', (array) data_get($result, 'awis_execution_gate.blockers'));
        $this->assertSame(2, $gate->calls);
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
        $this->assertContains($result['status'], ['served', 'no_claimable_task', 'waiting_on_dependencies', 'queue_scan_limit_exceeded']);
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
