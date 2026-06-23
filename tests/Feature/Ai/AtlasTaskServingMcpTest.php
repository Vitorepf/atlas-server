<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · A7 — the serving contract over the SECOND transport (MCP). Same service as the CLI; proves the
 * two tools are registered and that `atlas_next_task` serves a packet over JSON-RPC.
 */
final class AtlasTaskServingMcpTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-mcp-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_serving_tools_are_registered_in_tools_list(): void
    {
        $names = array_column(app(AtlasOpenBrainMcpService::class)->tools(), 'name');
        $this->assertContains('atlas_next_task', $names);
        $this->assertContains('atlas_task_report', $names);
    }

    public function test_atlas_next_task_serves_a_packet_over_json_rpc(): void
    {
        // Enqueue a packet into the (faked) shared store the MCP service's serving service will read.
        $this->orchestrator()->prepareAndEnqueue(['task_packet' => $this->input('mcp-1')]);

        $resp = app(AtlasOpenBrainMcpService::class)->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'atlas_next_task', 'arguments' => ['client_id' => 'mcp-client']],
        ]);

        $this->assertArrayNotHasKey('error', $resp, 'the tool is dispatched, not unknown');
        $envelope = data_get($resp, 'result.structuredContent', []);
        $this->assertSame('served', $envelope['status'] ?? null);
        $this->assertSame('mcp-client', $envelope['client_id'] ?? null);
        $this->assertSame('mcp-1', data_get($envelope, 'task.task_packet_id'));
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /** @return array<string, mixed> */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'mcp serve test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
